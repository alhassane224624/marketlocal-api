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
            'en_vente' => (bool) ($this->en_vente ?? true),
            // Image de couverture (= 1re image de la galerie).
            'image' => $this->image,
            // Note moyenne et nombre d'avis (withAvg / withCount dans le contrôleur).
            'note_moyenne' => $this->reviews_avg_note !== null ? round((float) $this->reviews_avg_note, 1) : null,
            'nombre_avis' => (int) ($this->reviews_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Boutique : version publique uniquement (sans commission).
            'shop' => PublicShopResource::make($this->whenLoaded('shop')),
            // Catégorie principale (= 1re catégorie).
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'nom' => $this->category->nom,
            ] : null),
            // Toutes les catégories ; à défaut (produit sans entrée pivot), la catégorie principale.
            'categories' => $this->whenLoaded('categories', fn () => ($this->categories->isNotEmpty()
                    ? $this->categories
                    : collect($this->relationLoaded('category') && $this->category ? [$this->category] : []))
                ->map(fn ($category) => ['id' => $category->id, 'nom' => $category->nom])
                ->values()),
            'images' => $this->whenLoaded('images', fn () => $this->images
                ->map(fn ($image) => ['id' => $image->id, 'url' => $image->url, 'position' => $image->position])
                ->values()),
            'reviews' => ReviewResource::collection($this->whenLoaded('reviews')),
        ];
    }
}
