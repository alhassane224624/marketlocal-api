<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\ApiFormRequest;

/**
 * Validation des filtres du catalogue (GET /products).
 */
class ListProductsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer'],
            'shop_id' => ['nullable', 'integer'],
            'prix_min' => ['nullable', 'numeric', 'min:0'],
            'prix_max' => ['nullable', 'numeric', 'min:0'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
