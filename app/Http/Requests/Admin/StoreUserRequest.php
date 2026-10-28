<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/** Création d'un compte par l'admin : contrairement à l'inscription, le rôle admin est permis. */
class StoreUserRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:acheteur,vendeur,livreur,admin'],
            'telephone' => ['required_if:role,livreur', 'nullable', 'string', 'max:20'],
            'ville' => ['nullable', 'string', 'max:100'],
        ];
    }
}
