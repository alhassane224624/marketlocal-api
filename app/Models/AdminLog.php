<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'action', 'cible_type', 'cible_id', 'description'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function admin() { return $this->belongsTo(User::class, 'admin_id'); }

    /**
     * Enregistre une action de l'admin connecté.
     * $cible : le modèle concerné (utilisateur, boutique, commande...), pour filtrer ensuite.
     */
    public static function record(string $action, ?Model $cible, string $description): void
    {
        static::create([
            'admin_id' => auth()->id(),
            'action' => $action,
            'cible_type' => $cible ? class_basename($cible) : null,
            'cible_id' => $cible?->getKey(),
            'description' => mb_substr($description, 0, 500),
        ]);
    }
}
