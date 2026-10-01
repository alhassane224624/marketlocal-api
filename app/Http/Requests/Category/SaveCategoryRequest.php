<?php

namespace App\Http\Requests\Category;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Création (POST) et modification (PUT) d'une catégorie.
 */
class SaveCategoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'nom' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'nom')->ignore($category?->id),
            ],
        ];
    }
}
