<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Services\SellerPayoutService;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Throwable;

class StripeConnectController extends Controller
{
    public function onboard(Request $request, StripeConnectService $stripe)
    {
        $shop = $request->user()->shop;
        if (! $shop) {
            return response()->json(['message' => "Vous n'avez pas de boutique."], 404);
        }
        if ($shop->statut !== 'valide') {
            return response()->json(['message' => 'Votre boutique doit être validée par un administrateur.'], 409);
        }

        $refresh = $request->string('refresh_url')->toString() ?: config('app.url').'/vendeur/stripe/refresh';
        $return = $request->string('return_url')->toString() ?: config('app.url').'/vendeur/stripe/retour';

        try {
            $url = $stripe->createOnboardingLink($shop->load('user'), $refresh, $return);
            return response()->json(['url' => $url]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => "Impossible de démarrer l'onboarding Stripe."], 502);
        }
    }

    public function settlePendingTransfers(Request $request, SellerPayoutService $payouts)
    {
        $shop = $request->user()->shop;
        if (! $shop || ! $shop->stripe_account_id || ! $shop->is_active) {
            return response()->json(['message' => 'Votre compte Stripe vendeur n’est pas encore actif.'], 409);
        }

        // Uniquement des commandes réellement payées et non remboursées : seller_amount
        // (part vendeur, commission déduite) n'est calculé qu'à la confirmation du paiement.
        $items = OrderItem::with('order')
            ->where('shop_id', $shop->id)
            ->whereIn('seller_transfer_status', SellerPayoutService::TRANSFERABLE)
            ->whereNotNull('seller_amount')
            ->whereHas('order', fn ($q) => $q
                ->whereIn('statut', SellerPayoutService::PAID_STATUSES)
                ->whereNull('refunded_at'))
            ->get();

        $done = 0;
        foreach ($items->groupBy('order_id') as $orderItems) {
            if ($payouts->transferShopItems($orderItems->first()->order, $shop, $orderItems) === 'completed') {
                $done++;
            }
        }

        return response()->json(['transferts_effectues' => $done]);
    }

    public function status(Request $request, StripeConnectService $stripe)
    {
        $shop = $request->user()->shop;
        if (! $shop) {
            return response()->json(['message' => "Vous n'avez pas de boutique."], 404);
        }

        try {
            $shop = $stripe->refreshStatus($shop);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Impossible de vérifier le statut Stripe.'], 502);
        }

        return response()->json([
            'stripe_account_id' => $shop->stripe_account_id,
            'kyc_status' => $shop->kyc_status,
            'is_active' => $shop->is_active,
        ]);
    }
}
