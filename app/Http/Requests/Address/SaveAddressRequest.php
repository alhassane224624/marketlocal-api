<?php

namespace App\Http\Requests\Address;

use App\Http\Requests\ApiFormRequest;

/**
 * Création (POST) et modification (PUT) d'une adresse de livraison.
 */
class SaveAddressRequest extends ApiFormRequest
{
    /**
     * Seul le propriétaire de l'adresse peut la modifier (PUT/DELETE).
     * Sans effet sur POST : il n'y a pas encore d'adresse liée à la route.
     */
    public function authorize(): bool
    {
        $address = $this->route('address');

        if (! $address) {
            return true;
        }

        return (int) $address->user_id === (int) $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'libelle' => ['nullable', 'string', 'max:50'],
            'nom_destinataire' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'max:20'],
            'adresse' => ['required', 'string', 'max:255'],
            'ville' => ['required', 'string', 'max:100'],
            'est_par_defaut' => ['sometimes', 'boolean'],
        ];
    }
}
