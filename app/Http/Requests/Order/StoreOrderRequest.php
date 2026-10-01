<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\ApiFormRequest;

class StoreOrderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantite' => ['required', 'integer', 'min:1', 'max:100'],

            // Option A (recommandée) : sélectionner une adresse enregistrée.
            // Doit appartenir à l'acheteur connecté (vérifié dans le contrôleur).
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],

            // Option B : envoyer une adresse ponctuelle. Si aucune des deux
            // options n'est fournie, on reprend l'adresse du profil (adresse/ville
            // de la table users), pour compatibilité avec l'existant.
            'adresse_livraison' => ['nullable', 'string', 'max:255'],
            'ville_livraison' => ['nullable', 'string', 'max:100'],
            'telephone_livraison' => ['nullable', 'string', 'max:20'],
        ];
    }
}
