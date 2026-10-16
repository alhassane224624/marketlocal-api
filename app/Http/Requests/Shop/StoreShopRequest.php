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
            // Ce que le vendeur veut vendre : lu par l'admin avant de valider la boutique.
            'activite' => ['required', 'string', 'min:30', 'max:2000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
