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
        ];
    }
}
