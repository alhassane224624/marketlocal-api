<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
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

    public function settlePendingTransfers(Request $request, StripeConnectService $stripe)
    {
        $shop = $request->user()->shop;
        if (! $shop || ! $shop->stripe_account_id || ! $shop->is_active) {
            return response()->json(['message' => 'Votre compte Stripe vendeur n’est pas encore actif.'], 409);
        }

        $items = \App\Models\OrderItem::with('order')
            ->where('shop_id', $shop->id)
            ->whereIn('seller_transfer_status', ['pending', 'failed'])
            ->whereHas('order', fn ($q) => $q->where('statut', '!=', 'annulee'))
            ->get();

        $done = 0;
        foreach ($items->groupBy('order_id') as $orderId => $orderItems) {
            $amount = (int) round($orderItems->sum(fn ($item) => (float) ($item->seller_amount ?? ((float) $item->prix_unitaire * $item->quantite))) * 100);
            if ($amount <= 0) continue;
            try {
                $transferId = $stripe->transfer($amount, $shop, 'marketlocal-transfer-order-'.$orderId.'-shop-'.$shop->id, (string) $orderId);
                $orderItems->each(fn ($item) => $item->update(['stripe_transfer_id' => $transferId, 'seller_transfer_status' => 'completed']));
                $done++;
            } catch (Throwable $e) {
                report($e);
                $orderItems->each(fn ($item) => $item->update(['seller_transfer_status' => 'failed']));
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
