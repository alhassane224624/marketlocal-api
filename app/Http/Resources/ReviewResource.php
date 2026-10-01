<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'user_id' => $this->user_id,
            'note' => $this->note,
            'commentaire' => $this->commentaire,
            'created_at' => $this->created_at,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'nom' => $this->product->nom,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
