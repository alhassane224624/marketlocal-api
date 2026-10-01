<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base commune des FormRequests de l'API.
 *
 * - 422 : { "message": "<première erreur>", "errors": { champ: [..] } }
 *   ("errors" est conservé tel quel pour le frontend, "message" est ajouté
 *   pour pouvoir l'afficher directement).
 * - 403 : { "message": "Non autorisé" }
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        throw new HttpResponseException(response()->json([
            'message' => $errors->first(),
            'errors' => $errors->toArray(),
        ], 422));
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            response()->json(['message' => 'Non autorisé'], 403)
        );
    }
}
