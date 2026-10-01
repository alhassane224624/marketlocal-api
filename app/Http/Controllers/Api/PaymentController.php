<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shop;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Throwable;

class PaymentController extends Controller
{
    public function __construct()
    {
        if ($secret = config('services.stripe.secret')) {
            Stripe::setApiKey($secret);
        }
    }

    public function createPaymentIntent(Request $request, Order $order)
    {
        if ((int) $order->buyer_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        if ($order->statut !== 'en_attente') {
            return response()->json(['message' => 'Cette commande a déjà été traitée'], 409);
        }

        if (! config('services.stripe.secret')) {
            return response()->json(['message' => "Le paiement Stripe n'est pas configuré sur le serveur."], 503);
        }

        if ($order->stripe_payment_intent_id) {
            $intent = PaymentIntent::retrieve($order->stripe_payment_intent_id);
            return response()->json(['client_secret' => $intent->client_secret]);
        }

        $items = $order->items()->with('shop')->get();
        if (config('services.stripe.connect_required')) {
            $missing = $items->filter(fn ($item) => ! $item->shop?->stripe_account_id || ! $item->shop?->is_active);
            if ($missing->isNotEmpty()) {
                return response()->json([
                    'message' => 'Un ou plusieurs vendeurs ne sont pas encore activés pour recevoir les paiements.',
                ], 422);
            }
        }

        $paymentIntent = PaymentIntent::create([
            'amount' => (int) round((float) $order->total * 100),
            'currency' => config('services.stripe.currency', 'mad'),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['order_id' => (string) $order->id],
        ], [
            'idempotency_key' => 'marketlocal-order-payment-'.$order->id,
        ]);

        $order->update(['stripe_payment_intent_id' => $paymentIntent->id]);

        return response()->json(['client_secret' => $paymentIntent->client_secret]);
    }

    public function refund(Request $request, Order $order)
    {
        if ($order->statut !== 'payee' || ! $order->stripe_payment_intent_id || $order->refunded_at) {
            return response()->json(['message' => 'Cette commande ne peut pas être remboursée dans son état actuel.'], 409);
        }

        if (! config('services.stripe.secret')) {
            return response()->json(['message' => "Stripe n'est pas configuré."], 503);
        }

        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $order->stripe_payment_intent_id,
                'amount' => (int) round((float) $order->total * 100),
                'metadata' => ['order_id' => (string) $order->id],
            ], ['idempotency_key' => 'marketlocal-refund-order-'.$order->id]);

            DB::transaction(function () use ($order, $refund) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                foreach ($locked->items()->whereNotNull('product_id')->get() as $item) {
                    \App\Models\Product::whereKey($item->product_id)->increment('stock', $item->quantite);
                }
                $locked->items()->update(['statut' => 'annulee']);
                $locked->update([
                    'statut' => 'annulee',
                    'refunded_at' => now(),
                    'stripe_refund_id' => $refund->id,
                ]);
            });

            return response()->json(['message' => 'Remboursement effectué.', 'refund_id' => $refund->id]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Le remboursement Stripe a échoué.'], 502);
        }
    }

    public function webhook(Request $request, StripeConnectService $stripeConnect)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        if (! $endpointSecret) {
            return response()->json(['error' => 'Secret webhook Stripe non configuré'], 503);
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Webhook invalide'], 400);
        }

        if ($event->type === 'account.updated') {
            $account = $event->data->object;
            $shop = Shop::where('stripe_account_id', $account->id)->first();
            if ($shop) {
                $enabled = (bool) ($account->charges_enabled && $account->payouts_enabled);
                $shop->update(['kyc_status' => $enabled ? 'verified' : 'pending', 'is_active' => $enabled]);
            }
            return response()->json(['received' => true]);
        }

        if ($event->type !== 'payment_intent.succeeded') {
            return response()->json(['received' => true]);
        }

        $paymentIntent = $event->data->object;
        $orderId = $paymentIntent->metadata->order_id ?? null;
        if (! $orderId) {
            return response()->json(['received' => true]);
        }

        $order = DB::transaction(function () use ($orderId, $paymentIntent) {
            $order = Order::with('items.shop')->whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                return null;
            }

            if ($order->statut === 'en_attente') {
                foreach ($order->items as $item) {
                    $gross = round((float) $item->prix_unitaire * (int) $item->quantite, 2);
                    $rate = (float) ($item->taux_commission ?? $item->shop?->commission ?? 0);
                    $commission = round($gross * $rate / 100, 2);
                    $seller = round($gross - $commission, 2);
                    $item->update([
                        'commission_amount' => $commission,
                        'seller_amount' => $seller,
                        'seller_transfer_status' => 'pending',
                    ]);
                }

                $order->items()->update(['statut' => 'payee']);
                $order->update([
                    'statut' => 'payee',
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'paid_at' => now(),
                ]);
            }

            return $order->fresh(['items.shop']);
        });

        if (! $order) {
            return response()->json(['received' => true]);
        }

        // Une commande peut contenir plusieurs boutiques : un transfer par boutique.
        foreach ($order->items->groupBy('shop_id') as $shopItems) {
            $shop = $shopItems->first()->shop;
            if (! $shop || ! $shop->stripe_account_id || ! $shop->is_active) {
                $shopItems->each(fn ($item) => $item->update(['seller_transfer_status' => 'blocked']));
                continue;
            }

            $amount = (int) round($shopItems->sum(fn ($item) => (float) $item->seller_amount) * 100);
            if ($amount <= 0) {
                $shopItems->each(fn ($item) => $item->update(['seller_transfer_status' => 'completed']));
                continue;
            }

            try {
                $transferId = $stripeConnect->transfer(
                    $amount,
                    $shop,
                    'marketlocal-transfer-order-'.$order->id.'-shop-'.$shop->id,
                    (string) $order->id
                );

                $shopItems->each(fn ($item) => $item->update([
                    'stripe_transfer_id' => $transferId,
                    'seller_transfer_status' => 'completed',
                ]));
            } catch (Throwable $e) {
                report($e);
                $shopItems->each(fn ($item) => $item->update(['seller_transfer_status' => 'failed']));
            }
        }

        return response()->json(['received' => true]);
    }
}
