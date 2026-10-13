<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'shop_id', 'product_nom', 'quantite', 'statut', 'prix_unitaire',
        'taux_commission', 'commission_amount', 'seller_amount', 'stripe_transfer_id',
        'seller_transfer_status', 'rembourse_at',
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

    /**
     * Fige la commission plateforme et la part vendeur de la ligne.
     * $transferStatus : pending (virement Stripe à faire) ou cash_collected
     * (paiement à la livraison : le vendeur a encaissé, la commission est due à la plateforme).
     */
    public function settleAmounts(string $transferStatus): void
    {
        $gross = round((float) $this->prix_unitaire * (int) $this->quantite, 2);
        $rate = (float) ($this->taux_commission ?? $this->shop?->commission ?? 0);
        $commission = round($gross * $rate / 100, 2);

        $this->update([
            'commission_amount' => $commission,
            'seller_amount' => round($gross - $commission, 2),
            'seller_transfer_status' => $transferStatus,
        ]);
    }

    /**
     * Lignes dont l'argent est réellement encaissé :
     *  - carte : commande payée (paid_at) et non remboursée ;
     *  - paiement à la livraison : article livré (le vendeur a encaissé).
     */
    public function scopeEncaissees($query)
    {
        return $query->where('statut', '!=', 'annulee')
            ->whereNull('rembourse_at') // remboursée après un litige
            ->where(function ($q) {
                $q->whereHas('order', fn ($o) => $o->where('mode_paiement', Order::PAIEMENT_CARTE)
                        ->whereNotNull('paid_at')
                        ->whereNull('refunded_at'))
                    ->orWhere(fn ($cod) => $cod->where('statut', 'livree')
                        ->whereHas('order', fn ($o) => $o->where('mode_paiement', Order::PAIEMENT_LIVRAISON)));
            });
    }

    public function order() { return $this->belongsTo(Order::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function shop() { return $this->belongsTo(Shop::class); }
}
