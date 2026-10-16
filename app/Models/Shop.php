<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $fillable = [
        'user_id', 'nom', 'description', 'activite', 'logo', 'logo_public_id', 'statut', 'motif_refus', 'valide_at',
        'commission', 'stripe_account_id', 'kyc_status', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'valide_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function products() { return $this->hasMany(Product::class); }
}
