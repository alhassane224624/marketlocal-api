<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    /** Nombre maximum de courses en cours (acceptées ou en route) par livreur. */
    public const MAX_COURSES_ACTIVES = 3;

    public const ACTIVES = ['disponible', 'acceptee', 'en_route'];

    protected $fillable = [
        'order_id', 'shop_id', 'livreur_id', 'statut', 'code',
        'accepted_at', 'picked_up_at', 'delivered_at',
        'frais', 'remuneration_livreur', 'remuneration_payee_at',
        'livreur_lat', 'livreur_lng', 'position_at',
        'recu_confirme_at', 'recu_confirme_auto', 'probleme_signale', 'probleme_signale_at',
        'litige_resolu_at', 'litige_resolution', 'litige_note', 'litige_livreur_paye',
        'rembourse_montant', 'stripe_refund_id',
    ];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'remuneration_payee_at' => 'datetime',
            'position_at' => 'datetime',
            'recu_confirme_at' => 'datetime',
            'recu_confirme_auto' => 'boolean',
            'probleme_signale_at' => 'datetime',
            'litige_resolu_at' => 'datetime',
            'litige_livreur_paye' => 'boolean',
            'rembourse_montant' => 'decimal:2',
            'frais' => 'decimal:2',
            'remuneration_livreur' => 'decimal:2',
        ];
    }

    /** Part des frais de livraison versée au livreur, en %. Le reste revient à la plateforme. */
    public static function partLivreur(): float
    {
        return (float) config('shop.delivery.livreur_share', 80);
    }

    /** Position affichée seulement si elle est récente (le livreur a pu fermer l'application). */
    public const POSITION_VALIDE_MINUTES = 10;

    /** Délai laissé au client pour confirmer la réception ou signaler un problème. */
    public static function delaiConfirmationHeures(): int
    {
        return (int) config('shop.delivery.confirmation_hours', 48);
    }

    /**
     * Courses livrées dont la rémunération peut être versée au livreur :
     * réception confirmée (par le client, automatiquement ou à la clôture d'un litige),
     * et pas de litige — ou un litige réglé par l'admin en accordant la rémunération.
     */
    public function scopePayables($query)
    {
        return $query->where('statut', 'livree')
            ->whereNotNull('recu_confirme_at')
            ->where(fn ($q) => $q->whereNull('probleme_signale_at')->orWhere('litige_livreur_paye', true));
    }

    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function order() { return $this->belongsTo(Order::class); }
    public function shop() { return $this->belongsTo(Shop::class); }
    public function livreur() { return $this->belongsTo(User::class, 'livreur_id'); }

    /** Articles de la commande appartenant à la boutique de cette livraison. */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id')->where('shop_id', $this->shop_id);
    }
}
