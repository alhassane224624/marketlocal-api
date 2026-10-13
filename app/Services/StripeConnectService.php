<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
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

    /**
     * Crée le compte Stripe du vendeur avec l'API Accounts v2 (recommandée par Stripe :
     * les nouvelles plateformes ne peuvent plus créer de comptes v1 par défaut).
     * Configuration « recipient » : le compte reçoit des virements (transfers) de la plateforme.
     */
    public function createExpressAccount(Shop $shop): string
    {
        if ($shop->stripe_account_id) {
            return $shop->stripe_account_id;
        }

        $country = config('services.stripe.connect_country');
        if (! $country) {
            throw new RuntimeException('STRIPE_CONNECT_COUNTRY doit être configuré avec un pays Stripe pris en charge pour le compte de la plateforme.');
        }

        $body = [
            'contact_email' => $shop->user->email,
            'display_name' => $shop->nom,
            'identity' => ['country' => strtolower($country)],
            // Tableau de bord Express : Stripe exige alors que la plateforme porte frais et pertes.
            'dashboard' => 'express',
            'defaults' => [
                'responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application'],
            ],
            'configuration' => [
                'recipient' => [
                    'capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]],
                ],
            ],
            'metadata' => ['shop_id' => (string) $shop->id],
            'include' => ['configuration.recipient'],
        ];

        try {
            $account = $this->v2('post', '/v2/core/accounts', $body);
        } catch (RuntimeException $e) {
            // La plateforme n'a pas (encore) accepté de porter les pertes de ses vendeurs dans
            // Stripe : on crée le compte avec le tableau de bord Stripe complet, où c'est Stripe
            // qui porte frais et pertes. Aucune acceptation n'est alors nécessaire.
            if (! str_contains($e->getMessage(), 'liability_unacknowledged')) {
                throw $e;
            }
            $body['dashboard'] = 'full';
            $body['defaults']['responsibilities'] = ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'];
            $account = $this->v2('post', '/v2/core/accounts', $body);
        }

        $shop->update([
            'stripe_account_id' => $account['id'],
            'kyc_status' => 'pending',
        ]);

        return $account['id'];
    }

    public function createOnboardingLink(Shop $shop, string $refreshUrl, string $returnUrl): string
    {
        $accountId = $this->createExpressAccount($shop);

        $link = $this->v2('post', '/v2/core/account_links', [
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                // Stripe déduit lui-même les configurations du compte (ici « recipient »).
                'account_onboarding' => [
                    'refresh_url' => $refreshUrl,
                    'return_url' => $returnUrl,
                ],
            ],
        ]);

        return $link['url'];
    }

    /** Le vendeur peut recevoir des virements quand stripe_transfers est « active ». */
    public function refreshStatus(Shop $shop): Shop
    {
        if (! $shop->stripe_account_id) {
            return $shop;
        }

        $account = $this->v2('get', '/v2/core/accounts/'.$shop->stripe_account_id, [
            'include' => ['configuration.recipient'],
        ]);
        $status = data_get($account, 'configuration.recipient.capabilities.stripe_balance.stripe_transfers.status');
        $enabled = $status === 'active';

        $shop->update([
            'kyc_status' => $enabled ? 'verified' : 'pending',
            'is_active' => $enabled,
        ]);

        return $shop->fresh();
    }

    /**
     * Appel à l'API Stripe v2 (JSON). Le SDK PHP n'est pas utilisé ici pour ne pas
     * dépendre de sa version. Lève une RuntimeException avec le message de Stripe.
     */
    private function v2(string $method, string $path, array $body = []): array
    {
        $secret = config('services.stripe.secret');
        if (! $secret) {
            throw new RuntimeException('Stripe n\'est pas configuré.');
        }

        $request = Http::withToken($secret)
            ->withHeaders(['Stripe-Version' => config('services.stripe.v2_version', '2026-09-30.endive')])
            ->acceptJson()
            ->timeout(30);

        // Windows/XAMPP : PHP n'a souvent pas de certificats racine. On réutilise ceux du SDK Stripe.
        $ca = base_path('vendor/stripe/stripe-php/data/ca-certificates.crt');
        if (is_file($ca)) {
            $request = $request->withOptions(['verify' => $ca]);
        }

        $url = 'https://api.stripe.com'.$path;
        if ($method === 'get') {
            // include[] en paramètre de requête : include[]=configuration.recipient
            $query = collect($body['include'] ?? [])->map(fn ($v) => 'include[]='.urlencode($v))->implode('&');
            $response = $request->get($url.($query ? '?'.$query : ''));
        } else {
            $response = $request->asJson()->post($url, $body);
        }

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            $code = $response->json('error.code');
            throw new RuntimeException('Stripe : '.$message.($code ? " ({$code})" : ''));
        }

        return $response->json();
    }

    /**
     * Vire la part du vendeur sur son compte Connect.
     *
     * $sourceCharge (ch_...) : rattache le virement au paiement du client. Sans lui, Stripe
     * prélève sur le solde « disponible » de la plateforme, qui est vide tant que les fonds
     * du paiement sont encore en attente (plusieurs jours) : le virement échouait alors
     * avec « insufficient funds ».
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

    /** Identifiant du paiement (ch_...) d'un PaymentIntent, pour source_transaction. */
    public function chargeIdFor(?string $paymentIntentId): ?string
    {
        if (! $paymentIntentId) {
            return null;
        }

        $intent = $this->client()->paymentIntents->retrieve($paymentIntentId, []);
        $charge = $intent->latest_charge ?? null;

        return is_string($charge) ? $charge : ($charge->id ?? null);
    }

    /**
     * Reprend un virement déjà fait au vendeur (remboursement client).
     * Sans cette reprise, la plateforme remboursait le client avec ses propres fonds
     * alors que le vendeur gardait sa part.
     */
    public function reverseTransfer(string $transferId, int $amountCents, string $idempotencyKey): string
    {
        $reversal = $this->client()->transfers->createReversal($transferId, [
            'amount' => $amountCents,
        ], [
            'idempotency_key' => $idempotencyKey,
        ]);

        return $reversal->id;
    }

    /** Annule un PaymentIntent pas encore payé (commande expirée). */
    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        $payments = $this->client()->paymentIntents;
        $intent = $payments->retrieve($paymentIntentId, []);

        if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'], true)) {
            $payments->cancel($paymentIntentId, []);
        }
    }
}
