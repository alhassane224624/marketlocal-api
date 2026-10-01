<?php

namespace App\Http\Requests\Shop;

use App\Http\Requests\ApiFormRequest;

class UpdateCommissionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'commission' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
