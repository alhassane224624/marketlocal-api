<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Livraison vue par l'acheteur, le vendeur, le livreur ou l'admin.
 *
 * - Le code de remise n'est montré qu'à l'acheteur (c'est lui qui le donne au livreur).
 * - Tant qu'une course n'est pas acceptée, un livreur ne voit que les villes
 *   (retrait / livraison) : adresses et téléphones seulement une fois la course à lui.
 *
 * Relations attendues : order.items, order.buyer, shop.user, livreur.
 */
class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $order = $this->relationLoaded('order') ? $this->order : null;
        $isBuyer = $user && $order && (int) $order->buyer_id === (int) $user->id;
        $isAssigned = $user && (int) $this->livreur_id === (int) $user->id;
        $seesDetails = $user && ($isAssigned || $isBuyer || $user->role === 'admin'
            || (int) $this->shop?->user_id === (int) $user->id);

        $items = $order && $order->relationLoaded('items')
            ? $order->items->where('shop_id', $this->shop_id)
            : collect();
        $montant = round($items->sum(fn ($i) => (float) $i->prix_unitaire * $i->quantite), 2);
        $vendeur = $this->relationLoaded('shop') && $this->shop?->relationLoaded('user') ? $this->shop->user : null;

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'statut' => $this->statut,
            'mode_paiement' => $order?->mode_paiement ?? 'carte',
            'nb_articles' => (int) $items->sum('quantite'),
            'montant_articles' => $montant,
            'frais' => (float) $this->frais,
            // Paiement à la livraison : articles + frais du colis, encaissés par le livreur.
            'a_encaisser' => $order?->isCashOnDelivery() ? round($montant + (float) $this->frais, 2) : 0,
            // Rémunération : visible par le livreur (y compris avant d'accepter) et l'admin.
            'remuneration_livreur' => $user && in_array($user->role, ['livreur', 'admin'], true)
                ? (float) $this->remuneration_livreur : null,
            'remuneration_payee' => $this->remuneration_payee_at !== null,
            // Dernière position du livreur, si elle est récente et la course en cours.
            'position' => $seesDetails && in_array($this->statut, ['acceptee', 'en_route'], true)
                && $this->position_at && $this->position_at->gt(now()->subMinutes(\App\Models\Delivery::POSITION_VALIDE_MINUTES))
                ? ['lat' => (float) $this->livreur_lat, 'lng' => (float) $this->livreur_lng, 'at' => $this->position_at]
                : null,
            'shop' => $this->whenLoaded('shop', fn () => ['id' => $this->shop->id, 'nom' => $this->shop->nom]),
            'livreur' => $this->whenLoaded('livreur', fn () => $this->livreur ? [
                'id' => $this->livreur->id,
                'name' => $this->livreur->name,
                'telephone' => $this->livreur->telephone,
            ] : null),
            'retrait' => [
                'ville' => $vendeur?->ville,
                'adresse' => $seesDetails ? $vendeur?->adresse : null,
                'telephone' => $seesDetails ? $vendeur?->telephone : null,
            ],
            'destination' => [
                'ville' => $order?->ville_livraison,
                'nom' => $seesDetails ? $order?->buyer?->name : null,
                'adresse' => $seesDetails ? $order?->adresse_livraison : null,
                'telephone' => $seesDetails ? $order?->telephone_livraison : null,
            ],
            'code' => $isBuyer ? $this->code : null,
            'created_at' => $this->created_at,
            'accepted_at' => $this->accepted_at,
            'picked_up_at' => $this->picked_up_at,
            'delivered_at' => $this->delivered_at,
            // Double confirmation : le client confirme la réception ou signale un problème.
            'recu_confirme_at' => $this->recu_confirme_at,
            'recu_confirme_auto' => (bool) $this->recu_confirme_auto,
            'probleme_signale' => $seesDetails ? $this->probleme_signale : null,
            'probleme_signale_at' => $this->probleme_signale_at,
            // Règlement du litige par l'admin.
            'litige_resolu_at' => $this->litige_resolu_at,
            'litige_resolution' => $this->litige_resolution,
            'litige_note' => $seesDetails ? $this->litige_note : null,
            'rembourse_montant' => $this->rembourse_montant !== null ? (float) $this->rembourse_montant : null,
            'confirmation_limite_at' => $this->delivered_at?->copy()->addHours(\App\Models\Delivery::delaiConfirmationHeures()),
        ];
    }
}
