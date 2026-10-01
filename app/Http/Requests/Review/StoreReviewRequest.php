<?php

namespace App\Http\Requests\Review;

use App\Http\Requests\ApiFormRequest;

class StoreReviewRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'integer', 'min:1', 'max:5'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
