<?php

namespace App\Models\Voitures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Compagnies\Compagnie;
use App\Models\Chauffeurs\Chauffeurs;
use App\Models\Voyages\Voyage;


class Voitures extends Model
{
    use HasFactory;

    protected $primaryKey = 'voit_id';
    protected $table = 'fandrio_app.voitures';

    protected $fillable = [
        'voit_matricule',
        'voit_marque',
        'voit_modele',
        'voit_places',
        'voit_statut',
        'comp_id',
        'chauff_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relation avec la compagnie propriétaire
     */
    public function compagnie() {
        return $this->belongsTo(Compagnie::class, 'comp_id', 'comp_id');
    }

    /**
     * Relation avec le chauffeur assigné
     */
    public function chauffeur() {
        return $this->belongsTo(Chauffeurs::class, 'chauff_id', 'chauff_id');
    }

    /**
     * Relation avec les voyages
     */
    public function voyages() {
        return $this->hasMany(Voyage::class, 'voit_id', 'voit_id');
    }

    /**
     * Scope pour les voitures actives
     */
    public function scopeActive($query) {
        return $query->where('voit_statut', 1);
    }

    /**
     * Scope pour les voitures d'une compagnie
     */
    public function scopeParCompagnie($query, $compagnieId) {
        return $query->where('comp_id', $compagnieId);
    }

    /**
     * Vérifier si la voiture est disponible pour une date
     */
    public function estDisponiblePourDate($date): bool
    {
        return !$this->voyages()
            ->where('voyage_date', $date)
            ->whereIn('voyage_statut', [1, 2]) // 1: programé, 2: en cours
            ->exists();
    }
}
