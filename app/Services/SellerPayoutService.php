<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Versement de la part vendeur (Stripe Connect) pour les lignes d'une commande payée.
 *
 * Statuts de order_items.seller_transfer_status :
 *   null      : commande pas encore payée, rien à verser
 *   pending   : payée, versement à faire
 *   blocked   : vendeur pas encore activé sur Stripe au moment du paiement
 *   failed    : le transfert Stripe a échoué
 *   completed : versé
 *   reversed / reversal_failed : versement repris (ou non) après remboursement
 *   cancelled : commande remboursée avant tout versement
 */
class SellerPayoutService
{
    /** Statuts d'une commande effectivement payée. */
    public const PAID_STATUSES = ['payee', 'expediee', 'livree'];

    /** Statuts d'une ligne dont la part vendeur reste à verser. */
    public const TRANSFERABLE = ['pending', 'blocked', 'failed'];

    public function __construct(private StripeConnectService $stripe) {}

    /**
     * Transfère en une fois la part vendeur des lignes $items (même commande, même boutique).
     * Renvoie le statut obtenu : completed, blocked ou failed.
     */
    public function transferShopItems(Order $order, ?Shop $shop, Collection $items): string
    {
        $items = $items->filter(fn ($item) => in_array($item->seller_transfer_status, self::TRANSFERABLE, true));

        if ($items->isEmpty()) {
            return 'completed';
        }

        if (! $shop || ! $shop->stripe_account_id || ! $shop->is_active) {
            $this->mark($items, ['seller_transfer_status' => 'blocked']);
            return 'blocked';
        }

        $amount = (int) round($items->sum(fn ($item) => (float) $item->seller_amount) * 100);
        if ($amount <= 0) {
            $this->mark($items, ['seller_transfer_status' => 'completed']);
            return 'completed';
        }

        // Stripe rejoue le même résultat pour une même clé pendant 24 h : après un échec,
        // on change de clé (horodatage de l'échec) pour que la nouvelle tentative parte vraiment.
        $key = 'marketlocal-transfer-order-'.$order->id.'-shop-'.$shop->id;
        $failedAt = $items->where('seller_transfer_status', 'failed')->max(fn ($item) => $item->updated_at?->timestamp);
        if ($failedAt) {
            $key .= '-retry-'.$failedAt;
        }

        try {
            $transferId = $this->stripe->transfer($amount, $shop, $key, (string) $order->id, $order->stripe_charge_id);
        } catch (Throwable $e) {
            report($e);
            $this->mark($items, ['seller_transfer_status' => 'failed']);
            return 'failed';
        }

        $this->mark($items, ['stripe_transfer_id' => $transferId, 'seller_transfer_status' => 'completed']);

        return 'completed';
    }

    private function mark(Collection $items, array $attributes): void
    {
        $items->each(fn ($item) => $item->update($attributes));
    }
}
