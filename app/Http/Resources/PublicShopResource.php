<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue publique d'une boutique (catalogue) : ne révèle ni la commission,
 * ni l'identifiant du propriétaire, ni le statut de validation.
 */
class PublicShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'logo' => $this->logo,
        ];
    }
}
