<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'nom' => $this->nom,
            'description' => $this->description,
            'logo' => $this->logo,
            'statut' => $this->statut,
            'commission' => $this->commission,
            'stripe_account_id' => $this->stripe_account_id,
            'kyc_status' => $this->kyc_status,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
