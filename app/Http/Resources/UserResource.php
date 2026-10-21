<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'telephone' => $this->telephone,
            'adresse' => $this->adresse,
            'ville' => $this->ville,
            'created_at' => $this->created_at,
            'suspendu_at' => $this->suspendu_at,
            'motif_suspension' => $this->motif_suspension,
            // Vue admin (GET /admin/users) : boutique et nombre de commandes passées.
            'shop' => $this->whenLoaded('shop', fn () => $this->shop ? [
                'id' => $this->shop->id,
                'nom' => $this->shop->nom,
                'statut' => $this->shop->statut,
            ] : null),
            'commandes_count' => $this->whenCounted('orders'),
        ];
    }
}
