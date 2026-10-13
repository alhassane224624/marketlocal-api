<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'buyer_id' => $this->buyer_id,
            'statut' => $this->statut,
            'total' => $this->total,
            'frais_livraison' => $this->frais_livraison ?? 0,
            'mode_paiement' => $this->mode_paiement ?? 'carte',
            'paid_at' => $this->paid_at,
            'adresse_livraison' => $this->adresse_livraison,
            'ville_livraison' => $this->ville_livraison,
            'telephone_livraison' => $this->telephone_livraison,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'buyer' => $this->whenLoaded('buyer', fn () => $this->buyer ? [
                'id' => $this->buyer->id,
                'name' => $this->buyer->name,
                'email' => $this->buyer->email,
            ] : null),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            // Livraisons par livreur (une par boutique) ; le code n'est visible que par l'acheteur.
            'deliveries' => DeliveryResource::collection($this->whenLoaded('deliveries')),
        ];
    }
}
