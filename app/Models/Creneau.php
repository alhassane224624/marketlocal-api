<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Creneau extends Model
{
    protected $table = 'creneaux';

    public const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi'];
    public const TYPES = ['cours' => 'Cours', 'td' => 'TD', 'tp' => 'TP', 'examen' => 'Examen'];

    protected $fillable = [
        'annee_scolaire_id', 'classe_id', 'matiere_id', 'professeur_id', 'salle_id',
        'jour', 'heure_debut', 'heure_fin', 'type_cours', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean', 'jour' => 'integer'];

    public function anneeScolaire() { return $this->belongsTo(AnneeScolaire::class); }
    public function classe() { return $this->belongsTo(Classe::class); }
    public function matiere() { return $this->belongsTo(Matiere::class); }
    public function professeur() { return $this->belongsTo(User::class, 'professeur_id'); }
    public function salle() { return $this->belongsTo(Salle::class); }
    public function seances() { return $this->hasMany(Planning::class, 'creneau_id'); }

    public function getJourLibelleAttribute(): string
    {
        return self::JOURS[$this->jour] ?? '?';
    }

    public function getHoraireAttribute(): string
    {
        return substr($this->heure_debut, 0, 5) . ' – ' . substr($this->heure_fin, 0, 5);
    }

    /** Durée en heures (pour les volumes horaires) */
    public function getDureeAttribute(): float
    {
        [$h1, $m1] = array_map('intval', explode(':', $this->heure_debut));
        [$h2, $m2] = array_map('intval', explode(':', $this->heure_fin));
        return round((($h2 * 60 + $m2) - ($h1 * 60 + $m1)) / 60, 2);
    }
}