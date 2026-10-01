<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'shop_id', 'product_nom', 'quantite', 'statut', 'prix_unitaire',
        'taux_commission', 'commission_amount', 'seller_amount', 'stripe_transfer_id',
        'seller_transfer_status',
    ];

    protected function casts(): array
    {
        return [
            'prix_unitaire' => 'decimal:2',
            'taux_commission' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'seller_amount' => 'decimal:2',
        ];
    }

    public function order() { return $this->belongsTo(Order::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function shop() { return $this->belongsTo(Shop::class); }
}
