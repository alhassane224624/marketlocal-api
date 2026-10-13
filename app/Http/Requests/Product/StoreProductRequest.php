<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\ApiFormRequest;
use App\Models\Product;

class StoreProductRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'prix' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],

            // Plusieurs catégories (la 1re est la catégorie principale).
            // category_id seul reste accepté pour compatibilité.
            'category_ids' => ['required_without:category_id', 'array', 'min:1', 'max:'.Product::MAX_CATEGORIES],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'category_id' => ['required_without:category_ids', 'integer', 'exists:categories,id'],

            // Plusieurs images (la 1re est l'image de couverture).
            // image seule reste acceptée pour compatibilité.
            'images' => ['nullable', 'array', 'max:'.Product::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
