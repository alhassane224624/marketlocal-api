<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\AdminLog;
use App\Models\Delivery;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Litiges de livraison (problème signalé par le client après la remise du colis).
 * Routes réservées à l'admin.
 */
class DisputeController extends Controller
{
    private const RELATIONS = [
        'order.items', 'order.buyer:id,name,email', 'shop.user:id,adresse,ville,telephone', 'livreur:id,name,telephone',
    ];

    // GET /api/admin/litiges : litiges ouverts d'abord, puis les 50 derniers réglés.
    public function index()
    {
        $ouverts = Delivery::with(self::RELATIONS)->whereNotNull('probleme_signale_at')->whereNull('litige_resolu_at')
            ->oldest('probleme_signale_at')->get();
        $regles = Delivery::with(self::RELATIONS)->whereNotNull('litige_resolu_at')
            ->latest('litige_resolu_at')->limit(50)->get();

        return response()->json($ouverts->concat($regles)->map(fn (Delivery $d) => DeliveryResource::make($d)->resolve() + [
            // Infos utiles à l'admin, en plus de la livraison.
            'acheteur' => $d->order?->buyer ? ['name' => $d->order->buyer->name, 'email' => $d->order->buyer->email] : null,
            'remboursable' => $this->amountFor($d),
        ])->values());
    }

    // POST /api/admin/litiges/{delivery}/resoudre
    // decision = livreur (problème non retenu) | rembourser ; payer_livreur ; note
    public function resolve(Request $request, Delivery $delivery, StripeConnectService $stripe)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:livreur,rembourser'],
            'payer_livreur' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $delivery->probleme_signale_at || $delivery->litige_resolu_at) {
            return response()->json(['message' => 'Ce litige est déjà réglé ou n’existe pas.'], 409);
        }

        $order = $delivery->order;

        // ---- Problème non retenu : on clôt, le livreur peut être payé. ----
        if ($data['decision'] === 'livreur') {
            $delivery->update([
                'litige_resolu_at' => now(),
                'litige_resolution' => 'livreur',
                'litige_note' => $data['note'] ?? null,
                'litige_livreur_paye' => true,
                'recu_confirme_at' => $delivery->recu_confirme_at ?? now(),
            ]);
            AdminLog::record('litige_resolu', $delivery, "Litige de la course n°{$delivery->id} (commande n°{$order->id}) : problème non retenu");

            return response()->json(DeliveryResource::make($delivery->fresh(self::RELATIONS))->resolve());
        }

        // ---- Remboursement du colis (articles de la boutique + frais de livraison). ----
        $montant = $this->amountFor($delivery);
        $items = $delivery->items()->get();
        $refundId = null;

        if (! $order->isCashOnDelivery()) {
            if (! $order->stripe_payment_intent_id || ! config('services.stripe.secret')) {
                return response()->json(['message' => "Remboursement par carte impossible : Stripe n'est pas configuré ou la commande n'a pas été payée en ligne."], 503);
            }

            // 1) Reprendre la part déjà virée au vendeur pour ces articles.
            foreach ($items->where('seller_transfer_status', 'completed')->whereNotNull('stripe_transfer_id')->groupBy('stripe_transfer_id') as $transferId => $lignes) {
                $cents = (int) round($lignes->sum(fn ($i) => (float) $i->seller_amount) * 100);
                try {
                    if ($cents > 0) {
                        $stripe->reverseTransfer($transferId, $cents, 'marketlocal-reversal-litige-'.$delivery->id.'-'.$transferId);
                    }
                    $delivery->items()->whereIn('id', $lignes->pluck('id'))->update(['seller_transfer_status' => 'reversed']);
                } catch (Throwable $e) {
                    report($e);
                    return response()->json(['message' => 'Impossible de reprendre le versement du vendeur : remboursement annulé. Réessayez.'], 502);
                }
            }

            // 2) Rembourser le client du montant du colis.
            try {
                $refund = $stripe->client()->refunds->create([
                    'payment_intent' => $order->stripe_payment_intent_id,
                    'amount' => (int) round($montant * 100),
                    'metadata' => ['order_id' => (string) $order->id, 'delivery_id' => (string) $delivery->id, 'motif' => 'litige'],
                ], ['idempotency_key' => 'marketlocal-refund-litige-'.$delivery->id]);
                $refundId = $refund->id;
            } catch (Throwable $e) {
                report($e);
                return response()->json(['message' => 'Le remboursement Stripe a échoué.'], 502);
            }
        }
        // Paiement à la livraison : le client a payé en espèces, le remboursement se fait hors
        // plateforme (espèces ou virement) ; on enregistre la décision et le montant.

        DB::transaction(function () use ($delivery, $data, $montant, $refundId) {
            $delivery->items()->update(['rembourse_at' => now()]);
            $delivery->items()->whereIn('seller_transfer_status', ['pending', 'failed', 'blocked'])
                ->update(['seller_transfer_status' => 'cancelled']);

            $delivery->update([
                'litige_resolu_at' => now(),
                'litige_resolution' => 'remboursement',
                'litige_note' => $data['note'] ?? null,
                'litige_livreur_paye' => (bool) ($data['payer_livreur'] ?? true),
                'recu_confirme_at' => $delivery->recu_confirme_at ?? now(),
                'rembourse_montant' => $montant,
                'stripe_refund_id' => $refundId,
            ]);
        });
        AdminLog::record('litige_resolu', $delivery, "Litige de la course n°{$delivery->id} (commande n°{$order->id}) : client remboursé de "
            .number_format($montant, 2, ',', ' ').' MAD');

        return response()->json(DeliveryResource::make($delivery->fresh(self::RELATIONS))->resolve());
    }

    /** Montant remboursable pour un colis : ses articles + ses frais de livraison. */
    private function amountFor(Delivery $delivery): float
    {
        $articles = $delivery->items()->get()->sum(fn ($i) => (float) $i->prix_unitaire * $i->quantite);

        return round($articles + (float) $delivery->frais, 2);
    }
}
