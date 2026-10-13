<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\ApiFormRequest;
use App\Models\Product;

class UpdateProductRequest extends ApiFormRequest
{
    /**
     * Seul le vendeur propriétaire du produit peut le modifier.
     */
    public function authorize(): bool
    {
        $product = $this->route('product');
        $shopId = $this->user()?->shop?->id;

        return $product !== null
            && $shopId !== null
            && (int) $product->shop_id === (int) $shopId;
    }

    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'prix' => ['sometimes', 'required', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],

            // Si envoyé, remplace la liste des catégories (la 1re devient la principale).
            'category_ids' => ['sometimes', 'required', 'array', 'min:1', 'max:'.Product::MAX_CATEGORIES],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],

            // Nouvelles images ajoutées à la suite des existantes.
            'images' => ['nullable', 'array', 'max:'.Product::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            // Images existantes à supprimer (doivent appartenir au produit, vérifié dans le contrôleur).
            'remove_image_ids' => ['nullable', 'array'],
            'remove_image_ids.*' => ['integer'],

            // Image existante à placer en couverture.
            'cover_image_id' => ['nullable', 'integer'],
        ];
    }
}
