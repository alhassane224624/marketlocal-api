<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Services\StripeConnectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Annule les commandes restées "en_attente" (non payées) au-delà du délai
 * configuré (config/shop.php: order_expiration_minutes) et restitue le stock.
 *
 * À lancer périodiquement : voir routes/console.php (Schedule::command).
 */
class CancelExpiredOrders extends Command
{
    protected $signature = 'orders:cancel-expired';

    protected $description = 'Annule les commandes non payées expirées et restitue le stock';

    public function handle(StripeConnectService $stripe): int
    {
        $minutes = (int) config('shop.order_expiration_minutes', 30);

        // Les commandes « paiement à la livraison » n'attendent pas de paiement en ligne : jamais expirées.
        $expiredIds = Order::where('statut', 'en_attente')
            ->where('mode_paiement', Order::PAIEMENT_CARTE)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->pluck('id');

        $annulees = 0;

        foreach ($expiredIds as $orderId) {
            DB::transaction(function () use ($orderId, &$annulees, $stripe) {
                // Verrou : si un paiement Stripe arrive au même instant, il gagne.
                $order = Order::whereKey($orderId)->lockForUpdate()->first();

                if (! $order || $order->statut !== 'en_attente' || $order->isCashOnDelivery()) {
                    return;
                }

                // Le client a peut-être déjà payé (webhook pas encore reçu) : on ne l'annule pas.
                // Sinon on ferme le paiement côté Stripe pour qu'il ne puisse plus être réglé.
                if ($order->stripe_payment_intent_id && config('services.stripe.secret')) {
                    try {
                        $status = $stripe->client()->paymentIntents->retrieve($order->stripe_payment_intent_id, [])->status;
                        if (in_array($status, ['succeeded', 'processing'], true)) {
                            return;
                        }
                        $stripe->cancelPaymentIntent($order->stripe_payment_intent_id);
                    } catch (Throwable $e) {
                        report($e); // Le webhook remboursera un éventuel paiement tardif.
                    }
                }

                foreach ($order->items()->whereNotNull('product_id')->get() as $item) {
                    Product::whereKey($item->product_id)->increment('stock', $item->quantite);
                }

                $order->items()->update(['statut' => 'annulee']);
                $order->update(['statut' => 'annulee']);
                $annulees++;
            });
        }

        $this->info("{$annulees} commande(s) expirée(s) annulée(s), stock restitué.");

        return self::SUCCESS;
    }
}
