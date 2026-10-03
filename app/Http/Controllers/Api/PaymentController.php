<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Services\SellerPayoutService;
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

    public function refund(Request $request, Order $order, StripeConnectService $stripe)
    {
        if ($order->statut !== 'payee' || ! $order->stripe_payment_intent_id || $order->refunded_at) {
            return response()->json(['message' => 'Cette commande ne peut pas être remboursée dans son état actuel.'], 409);
        }

        if (! config('services.stripe.secret')) {
            return response()->json(['message' => "Stripe n'est pas configuré."], 503);
        }

        try {
            $refundId = $stripe->refund(
                $order->stripe_payment_intent_id,
                (int) round((float) $order->total * 100),
                (string) $order->id
            );
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Le remboursement Stripe a échoué.'], 502);
        }

        $refunded = DB::transaction(function () use ($order, $refundId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->refunded_at) {
                return false; // déjà traité par une requête concurrente (même remboursement Stripe)
            }

            foreach ($locked->items()->whereNotNull('product_id')->get() as $item) {
                Product::whereKey($item->product_id)->increment('stock', $item->quantite);
            }
            $locked->items()->update(['statut' => 'annulee']);
            // Rien n'a encore été versé pour ces lignes : on n'y touchera plus.
            $locked->items()
                ->whereIn('seller_transfer_status', SellerPayoutService::TRANSFERABLE)
                ->update(['seller_transfer_status' => 'cancelled']);
            $locked->update([
                'statut' => 'annulee',
                'refunded_at' => now(),
                'stripe_refund_id' => $refundId,
            ]);

            return true;
        });

        if ($refunded) {
            $this->reverseSellerTransfers($order, $stripe);
        }

        return response()->json(['message' => 'Remboursement effectué.', 'refund_id' => $refundId]);
    }

    public function webhook(Request $request, StripeConnectService $stripeConnect, SellerPayoutService $payouts)
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

        $chargeId = $paymentIntent->latest_charge ?? null;
        if (is_object($chargeId)) {
            $chargeId = $chargeId->id ?? null;
        }

        $result = DB::transaction(function () use ($orderId, $paymentIntent, $chargeId) {
            $order = Order::with('items.shop')->whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                return null;
            }

            // Paiement arrivé après l'annulation (expiration ou admin) : le stock a déjà
            // été remis en vente, on rembourse l'acheteur au lieu de garder l'argent.
            if ($order->statut === 'annulee' && ! $order->paid_at) {
                return $order->refunded_at ? null : ['refund' => $order];
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
                    'stripe_charge_id' => $chargeId,
                    'paid_at' => now(),
                ]);
            }

            return ['pay' => $order->fresh(['items.shop'])];
        });

        if (isset($result['refund'])) {
            if (! $this->refundLatePayment($result['refund'], $paymentIntent, $stripeConnect)) {
                // Réponse en erreur : Stripe renverra l'événement plus tard.
                return response()->json(['error' => 'Remboursement à réessayer'], 500);
            }
            return response()->json(['received' => true]);
        }

        if (isset($result['pay'])) {
            $order = $result['pay'];
            // Une commande peut contenir plusieurs boutiques : un transfer par boutique.
            // Seules les lignes « pending » : un webhook rejoué ne reverse rien deux fois.
            $pending = $order->items->where('seller_transfer_status', 'pending');
            foreach ($pending->groupBy('shop_id') as $shopItems) {
                $payouts->transferShopItems($order, $shopItems->first()->shop, $shopItems);
            }
        }

        return response()->json(['received' => true]);
    }

    private function refundLatePayment(Order $order, $paymentIntent, StripeConnectService $stripe): bool
    {
        try {
            $refundId = $stripe->refund($paymentIntent->id, (int) $paymentIntent->amount_received, (string) $order->id);
        } catch (Throwable $e) {
            report($e);
            return false;
        }

        $order->update([
            'stripe_payment_intent_id' => $paymentIntent->id,
            'refunded_at' => now(),
            'stripe_refund_id' => $refundId,
        ]);

        return true;
    }

    /** Reprend aux vendeurs les montants déjà transférés pour une commande remboursée. */
    private function reverseSellerTransfers(Order $order, StripeConnectService $stripe): void
    {
        $transferred = $order->items()
            ->whereNotNull('stripe_transfer_id')
            ->where('seller_transfer_status', 'completed')
            ->get()
            ->groupBy('stripe_transfer_id');

        foreach ($transferred as $transferId => $items) {
            try {
                $stripe->reverseTransfer($transferId, 'marketlocal-reverse-'.$transferId);
                $status = 'reversed';
            } catch (Throwable $e) {
                // Ex. solde vendeur insuffisant : à régulariser à la main depuis le dashboard Stripe.
                report($e);
                $status = 'reversal_failed';
            }

            $items->each(fn ($item) => $item->update(['seller_transfer_status' => $status]));
        }
    }
}
