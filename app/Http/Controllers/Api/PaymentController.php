<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
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

    // GET /api/payment-methods (public) : modes de paiement proposés au panier.
    public function methods()
    {
        $maxCod = (float) config('shop.cash_on_delivery.max_total', 0);

        return response()->json([
            [
                'code' => Order::PAIEMENT_CARTE,
                'label' => 'Carte bancaire',
                'description' => 'Paiement en ligne sécurisé par Stripe.',
                'disponible' => (bool) config('services.stripe.secret'),
            ],
            [
                'code' => Order::PAIEMENT_LIVRAISON,
                'label' => 'Paiement à la livraison',
                'description' => 'Réglez en espèces à la réception de votre colis.',
                'disponible' => (bool) config('shop.cash_on_delivery.enabled', true),
                'montant_max' => $maxCod > 0 ? $maxCod : null,
            ],
        ]);
    }

    // GET /api/livraison/tarif (public) : frais par colis, pour le récapitulatif du panier.
    public function deliveryFee()
    {
        return response()->json(['frais_par_colis' => Order::fraisParColis()]);
    }

    public function createPaymentIntent(Request $request, Order $order, StripeConnectService $stripeConnect)
    {
        if ((int) $order->buyer_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        if ($order->isCashOnDelivery()) {
            return response()->json(['message' => 'Cette commande sera réglée à la livraison : aucun paiement en ligne requis.'], 409);
        }

        if ($order->statut !== 'en_attente') {
            return response()->json(['message' => 'Cette commande a déjà été traitée'], 409);
        }

        if (! config('services.stripe.secret')) {
            return response()->json(['message' => "Le paiement Stripe n'est pas configuré sur le serveur."], 503);
        }

        $idempotencyKey = 'marketlocal-order-payment-'.$order->id;

        if ($order->stripe_payment_intent_id) {
            $intent = PaymentIntent::retrieve($order->stripe_payment_intent_id);

            // Déjà payé mais le webhook n'est pas (encore) passé : on valide la commande ici.
            if ($intent->status === 'succeeded') {
                $this->processSucceededIntent($intent, $stripeConnect);

                return response()->json([
                    'message' => 'Paiement déjà reçu : votre commande est payée.',
                    'paid' => true,
                ], 409);
            }

            // Paiement encore utilisable : on le réutilise.
            if ($intent->status !== 'canceled') {
                return response()->json(['client_secret' => $intent->client_secret]);
            }

            // Paiement annulé côté Stripe : on en recrée un (nouvelle clé d'idempotence).
            $idempotencyKey .= '-after-'.$intent->id;
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
            'idempotency_key' => $idempotencyKey,
        ]);

        $order->update(['stripe_payment_intent_id' => $paymentIntent->id]);

        return response()->json(['client_secret' => $paymentIntent->client_secret]);
    }

    public function refund(Request $request, Order $order, StripeConnectService $stripeConnect)
    {
        if ($order->statut !== 'payee' || ! $order->stripe_payment_intent_id || $order->refunded_at) {
            return response()->json(['message' => 'Cette commande ne peut pas être remboursée dans son état actuel.'], 409);
        }

        if (! config('services.stripe.secret')) {
            return response()->json(['message' => "Stripe n'est pas configuré."], 503);
        }

        // 1) On reprend d'abord l'argent déjà viré aux vendeurs. Si une reprise échoue,
        //    on ne rembourse pas le client : sinon la plateforme paierait de sa poche.
        $transferred = $order->items()
            ->where('seller_transfer_status', 'completed')
            ->whereNotNull('stripe_transfer_id')
            ->get()
            ->groupBy('stripe_transfer_id');

        foreach ($transferred as $transferId => $items) {
            $amount = (int) round($items->sum(fn ($item) => (float) $item->seller_amount) * 100);
            try {
                if ($amount > 0) {
                    $stripeConnect->reverseTransfer($transferId, $amount, 'marketlocal-reversal-'.$transferId);
                }
                $order->items()->whereIn('id', $items->pluck('id'))->update(['seller_transfer_status' => 'reversed']);
            } catch (Throwable $e) {
                report($e);
                return response()->json([
                    'message' => "Impossible de reprendre le versement déjà effectué à un vendeur : remboursement annulé. Réessayez ou vérifiez le solde du compte vendeur dans Stripe.",
                ], 502);
            }
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
                $locked->deliveries()->whereIn('statut', \App\Models\Delivery::ACTIVES)->update(['statut' => 'annulee']);
                // Lignes jamais virées : plus rien à verser au vendeur.
                $locked->items()->whereIn('seller_transfer_status', ['pending', 'failed', 'blocked'])
                    ->update(['seller_transfer_status' => 'cancelled']);
                $locked->update([
                    'statut' => 'annulee',
                    'refunded_at' => now(),
                    'stripe_refund_id' => $refund->id,
                ]);
            });

            AdminLog::record('commande_remboursee', $order, 'Commande n°'.$order->id.' remboursée ('.number_format((float) $order->total, 2, ',', ' ').' MAD)');

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
                // Comptes Accounts v2 : on relit le statut de la capacité stripe_transfers
                // (charges_enabled ne s'applique pas à un compte qui ne fait que recevoir des virements).
                try {
                    $stripeConnect->refreshStatus($shop);
                } catch (Throwable $e) {
                    report($e);
                }
            }
            return response()->json(['received' => true]);
        }

        if ($event->type !== 'payment_intent.succeeded') {
            return response()->json(['received' => true]);
        }

        $this->processSucceededIntent($event->data->object, $stripeConnect);

        return response()->json(['received' => true]);
    }

    /**
     * Valide une commande payée et vire leur part aux vendeurs.
     * Appelé par le webhook, ou au retour sur la page de paiement si le webhook n'est pas passé.
     * Rejouable sans risque (verrou + statuts des lignes).
     */
    private function processSucceededIntent($paymentIntent, StripeConnectService $stripeConnect): void
    {
        $orderId = $paymentIntent->metadata->order_id ?? null;
        if (! $orderId) {
            return;
        }

        // Vrai seulement au premier passage en « payee » (webhook et retour client peuvent se croiser).
        $vientDetrePayee = false;

        $order = DB::transaction(function () use ($orderId, $paymentIntent, &$vientDetrePayee) {
            $order = Order::with('items.shop')->whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                return null;
            }

            // Le montant réellement encaissé doit correspondre à la commande.
            $expected = (int) round((float) $order->total * 100);
            $received = (int) ($paymentIntent->amount_received ?? $paymentIntent->amount ?? 0);
            $currencyOk = strtolower((string) $paymentIntent->currency) === strtolower((string) config('services.stripe.currency', 'mad'));
            if ($received !== $expected || ! $currencyOk) {
                report(new \RuntimeException("Paiement Stripe {$paymentIntent->id} : montant {$received} {$paymentIntent->currency} différent de la commande #{$order->id} ({$expected})."));
                return $order;
            }

            // Une commande « paiement à la livraison » ne passe jamais par Stripe.
            if ($order->statut === 'en_attente' && ! $order->isCashOnDelivery()) {
                foreach ($order->items as $item) {
                    $item->settleAmounts('pending');
                }

                $order->items()->update(['statut' => 'payee']);
                $order->update([
                    'statut' => 'payee',
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'paid_at' => now(),
                ]);
                $vientDetrePayee = true;
            }

            return $order->fresh(['items.shop']);
        });

        if (! $order) {
            return;
        }

        if ($vientDetrePayee) {
            $order->sendConfirmationMails();
        }

        // Paiement arrivé après l'annulation de la commande (expirée ou annulée par l'admin) :
        // le client a été débité pour une commande annulée, on le rembourse.
        if ($order->statut === 'annulee') {
            $this->refundLatePayment($order, $paymentIntent);
            return;
        }

        if ($order->statut !== 'payee' || $order->refunded_at) {
            return;
        }

        // Paiement source des virements (voir StripeConnectService::transfer).
        $charge = $paymentIntent->latest_charge ?? null;
        $sourceCharge = is_string($charge) ? $charge : ($charge->id ?? null);

        // Une commande peut contenir plusieurs boutiques : un transfer par boutique.
        // Seules les lignes pas encore virées sont traitées (le webhook peut être rejoué).
        $toTransfer = $order->items->whereIn('seller_transfer_status', ['pending', 'failed', 'blocked']);
        foreach ($toTransfer->groupBy('shop_id') as $shopItems) {
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
                    (string) $order->id,
                    $sourceCharge
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
    }

    private function refundLatePayment(Order $order, $paymentIntent): void
    {
        if ($order->refunded_at || ! config('services.stripe.secret')) {
            return;
        }

        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $paymentIntent->id,
                'metadata' => ['order_id' => (string) $order->id, 'motif' => 'commande_annulee'],
            ], ['idempotency_key' => 'marketlocal-refund-cancelled-order-'.$order->id]);

            $order->update([
                'stripe_payment_intent_id' => $paymentIntent->id,
                'refunded_at' => now(),
                'stripe_refund_id' => $refund->id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
