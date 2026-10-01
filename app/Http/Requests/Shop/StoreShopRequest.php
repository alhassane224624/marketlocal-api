<?php

namespace App\Http\Requests\Shop;

use App\Http\Requests\ApiFormRequest;

class StoreShopRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
