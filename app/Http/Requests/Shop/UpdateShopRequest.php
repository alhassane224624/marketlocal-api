<?php

namespace App\Http\Requests\Shop;

use App\Http\Requests\ApiFormRequest;

class UpdateShopRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'activite' => ['sometimes', 'required', 'string', 'min:30', 'max:2000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
