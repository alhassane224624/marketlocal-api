<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'buyer_id', 'statut', 'total', 'adresse_livraison', 'ville_livraison',
        'telephone_livraison', 'stripe_payment_intent_id', 'stripe_charge_id', 'paid_at', 'refunded_at',
        'stripe_refund_id',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function buyer() { return $this->belongsTo(User::class, 'buyer_id'); }
    public function items() { return $this->hasMany(OrderItem::class); }
}
