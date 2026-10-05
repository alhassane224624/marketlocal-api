<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'category_id' => $this->category_id,
            'nom' => $this->nom,
            'description' => $this->description,
            'prix' => $this->prix,
            'stock' => $this->stock,
            'image' => $this->image,
            // Présents quand la requête a chargé withAvg / withCount('reviews').
            'note_moyenne' => $this->when(
                array_key_exists('reviews_avg_note', $this->resource->getAttributes()),
                fn () => $this->reviews_avg_note !== null ? round((float) $this->reviews_avg_note, 1) : null
            ),
            'nombre_avis' => $this->when(
                array_key_exists('reviews_count', $this->resource->getAttributes()),
                fn () => (int) $this->reviews_count
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Boutique : version publique uniquement (sans commission).
            'shop' => PublicShopResource::make($this->whenLoaded('shop')),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'nom' => $this->category->nom,
            ]),
            'reviews' => ReviewResource::collection($this->whenLoaded('reviews')),
        ];
    }
}
