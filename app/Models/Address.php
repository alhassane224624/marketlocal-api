<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $fillable = [
        'user_id',
        'libelle',
        'nom_destinataire',
        'telephone',
        'adresse',
        'ville',
        'est_par_defaut',
    ];

    protected function casts(): array
    {
        return [
            'est_par_defaut' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
