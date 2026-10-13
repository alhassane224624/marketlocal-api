<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const PAIEMENT_CARTE = 'carte';
    public const PAIEMENT_LIVRAISON = 'livraison';
    public const MODES_PAIEMENT = [self::PAIEMENT_CARTE, self::PAIEMENT_LIVRAISON];

    protected $fillable = [
        'buyer_id', 'statut', 'total', 'frais_livraison', 'frais_livraison_colis', 'mode_paiement', 'adresse_livraison', 'ville_livraison',
        'telephone_livraison', 'stripe_payment_intent_id', 'paid_at', 'refunded_at', 'stripe_refund_id',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    /** Frais de livraison par colis (= par boutique). Modifiable dans config/shop.php. */
    public static function fraisParColis(): float
    {
        return (float) config('shop.delivery.fee_per_shop', 20);
    }

    public function isCashOnDelivery(): bool
    {
        return $this->mode_paiement === self::PAIEMENT_LIVRAISON;
    }

    public function buyer() { return $this->belongsTo(User::class, 'buyer_id'); }
    public function items() { return $this->hasMany(OrderItem::class); }
    public function deliveries() { return $this->hasMany(Delivery::class); }

    /**
     * Fait passer les articles d'une boutique à $statut (expediee ou livree), puis recalcule
     * le statut de la commande à partir de tous ses articles (commande multi-vendeurs).
     * Paiement à la livraison : chaque vendeur encaisse sa part quand ses articles sont livrés.
     * À appeler dans une transaction, sur une commande verrouillée.
     */
    public function advanceShopItems(int $shopId, string $statut): void
    {
        $this->items()->where('shop_id', $shopId)->update(['statut' => $statut]);

        if ($this->isCashOnDelivery() && $statut === 'livree') {
            foreach ($this->items()->where('shop_id', $shopId)->with('shop')->get() as $item) {
                $item->settleAmounts('cash_collected');
            }
        }

        $statuts = $this->items()->pluck('statut');
        if ($statuts->every(fn ($s) => $s === 'livree')) {
            $nouveau = 'livree';
        } elseif ($statuts->every(fn ($s) => in_array($s, ['expediee', 'livree'], true))) {
            $nouveau = 'expediee';
        } else {
            $nouveau = $this->isCashOnDelivery() ? 'en_attente' : 'payee';
        }

        $this->update(array_filter([
            'statut' => $nouveau,
            'paid_at' => $this->isCashOnDelivery() && $nouveau === 'livree' ? now() : null,
        ]));
    }
}
