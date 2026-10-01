<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de commande. Le nom du produit vient du snapshot enregistré à
 * l'achat (product_nom), donc l'historique reste lisible même si le
 * produit est supprimé ensuite.
 *
 * Le taux de commission n'est volontairement PAS exposé ici
 * (acheteurs et vendeurs n'ont pas à le voir sur chaque ligne).
 */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'shop_id' => $this->shop_id,
            'quantite' => $this->quantite,
            'statut' => $this->statut,
            'prix_unitaire' => $this->prix_unitaire,
            'product' => [
                'id' => $this->product_id,
                'nom' => $this->product_nom
                    ?? ($this->relationLoaded('product') ? $this->product?->nom : null),
            ],
        ];
    }
}
