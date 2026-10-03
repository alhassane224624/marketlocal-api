<?php

namespace App\Services;

use App\Models\Shop;
use Stripe\StripeClient;
use RuntimeException;

class StripeConnectService
{
    public function client(): StripeClient
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            throw new RuntimeException('Stripe n\'est pas configuré.');
        }

        return new StripeClient($secret);
    }

    public function createExpressAccount(Shop $shop): string
    {
        if ($shop->stripe_account_id) {
            return $shop->stripe_account_id;
        }

        $country = config('services.stripe.connect_country');
        if (! $country) {
            throw new RuntimeException('STRIPE_CONNECT_COUNTRY doit être configuré avec un pays Stripe pris en charge pour le compte de la plateforme.');
        }

        $account = $this->client()->accounts->create([
            'type' => 'express',
            'country' => $country,
            'email' => $shop->user->email,
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'business_profile' => [
                'name' => $shop->nom,
            ],
        ]);

        $shop->update([
            'stripe_account_id' => $account->id,
            'kyc_status' => 'pending',
        ]);

        return $account->id;
    }

    public function createOnboardingLink(Shop $shop, string $refreshUrl, string $returnUrl): string
    {
        $accountId = $this->createExpressAccount($shop);

        $link = $this->client()->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return $link->url;
    }

    public function refreshStatus(Shop $shop): Shop
    {
        if (! $shop->stripe_account_id) {
            return $shop;
        }

        $account = $this->client()->accounts->retrieve($shop->stripe_account_id, []);
        $enabled = (bool) ($account->charges_enabled && $account->payouts_enabled);

        $shop->update([
            'kyc_status' => $enabled ? 'verified' : 'pending',
            'is_active' => $enabled,
        ]);

        return $shop->fresh();
    }

    /**
     * Transfert de la part vendeur. $sourceCharge (id du paiement « ch_... ») rattache
     * le transfert au paiement : Stripe l'exécute même si les fonds sont encore en
     * attente sur le solde de la plateforme.
     */
    public function transfer(int $amountCents, Shop $shop, string $idempotencyKey, string $orderId, ?string $sourceCharge = null): string
    {
        if (! $shop->stripe_account_id || ! $shop->is_active) {
            throw new RuntimeException("Le vendeur {$shop->nom} n'est pas encore activé sur Stripe Connect.");
        }

        $params = [
            'amount' => $amountCents,
            'currency' => config('services.stripe.currency', 'mad'),
            'destination' => $shop->stripe_account_id,
            'metadata' => ['order_id' => $orderId, 'shop_id' => $shop->id],
        ];

        if ($sourceCharge) {
            $params['source_transaction'] = $sourceCharge;
        }

        $transfer = $this->client()->transfers->create($params, [
            'idempotency_key' => $idempotencyKey,
        ]);

        return $transfer->id;
    }

    /** Reprend au vendeur un transfert déjà effectué (remboursement de l'acheteur). */
    public function reverseTransfer(string $transferId, string $idempotencyKey): string
    {
        $reversal = $this->client()->transfers->createReversal($transferId, [], [
            'idempotency_key' => $idempotencyKey,
        ]);

        return $reversal->id;
    }

    /** Rembourse intégralement un paiement et renvoie l'id du remboursement. */
    public function refund(string $paymentIntentId, int $amountCents, string $orderId): string
    {
        $refund = $this->client()->refunds->create([
            'payment_intent' => $paymentIntentId,
            'amount' => $amountCents,
            'metadata' => ['order_id' => $orderId],
        ], [
            'idempotency_key' => 'marketlocal-refund-order-'.$orderId,
        ]);

        return $refund->id;
    }

    /**
     * Annule un PaymentIntent pour qu'il ne puisse plus être payé.
     * Renvoie false si le paiement a déjà abouti ou est en cours de traitement :
     * la commande ne doit alors pas être annulée (le webhook va la marquer payée).
     */
    public function cancelPaymentIntent(string $paymentIntentId): bool
    {
        $intent = $this->client()->paymentIntents->retrieve($paymentIntentId, []);

        if (in_array($intent->status, ['succeeded', 'processing'], true)) {
            return false;
        }

        if ($intent->status !== 'canceled') {
            $this->client()->paymentIntents->cancel($paymentIntentId, []);
        }

        return true;
    }
}
