<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/** Modification d'un compte par l'admin : mot de passe facultatif (vide = inchangé). */
class UpdateUserRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
        if ($this->password === '') {
            $this->merge(['password' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'in:acheteur,vendeur,livreur,admin'],
            'telephone' => ['required_if:role,livreur', 'nullable', 'string', 'max:20'],
            'ville' => ['nullable', 'string', 'max:100'],
        ];
    }
}
