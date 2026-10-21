<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'telephone',
        'adresse',
        'ville',
        'suspendu_at',
        'motif_suspension',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspendu_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspendu_at !== null;
    }

    public function shop()
    {
        return $this->hasOne(Shop::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    // Courses du livreur.
    public function deliveries()
    {
        return $this->hasMany(Delivery::class, 'livreur_id');
    }
}
