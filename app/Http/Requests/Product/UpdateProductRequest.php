<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\ApiFormRequest;

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
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
