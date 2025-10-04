<?php

namespace App\Models\Compagnies;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Provinces\Province;
use App\Models\Utilisateurs\Utilisateur;
use App\Models\Paiements\TypePaiement;
use Illuminate\Database\Eloquent\Model;

class Compagnie extends Model
{
    use HasFactory;

    protected $table = 'fandrio_app.compagnies';
    protected $primaryKey = 'comp_id';

    protected $fillable = [
        'comp_nom',
        'comp_nif',
        'comp_stat',
        'comp_description',
        'comp_phone',
        'comp_email',
        'comp_adresse',
        'comp_logo',
        'comp_statut'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relation avec les utilisateurs (admins de la compagnie)
     */
    public function utilisateurs()
    {
        return $this->hasMany(Utilisateur::class, 'comp_id', 'comp_id');
    }

    /**
     * Relation avec les provinces desservies
     */
    public function provincesDesservies()
    {
        return $this->belongsToMany(
            Province::class,
            'fandrio_app.compagnie_provinces',
            'comp_id',
            'pro_id'
        )->withPivot('comp_pro_statut')
         ->wherePivot('comp_pro_statut', 1);
    }

    /**
     * Relation avec les modes de paiement acceptés
     */
    public function modesPaiement()
    {
        return $this->belongsToMany(
            TypePaiement::class,
            'fandrio_app.compagnie_paiements',
            'comp_id',
            'type_paie_id'
        )->withPivot('comp_paie_statut')
         ->wherePivot('comp_paie_statut', 1);
    }

    /**
     * Relation avec les trajets
     */
    public function trajets()
    {
        return $this->hasMany(Trajet::class, 'comp_id', 'comp_id');
    }

    /**
     * Relation avec les voitures
     */
    public function voitures()
    {
        return $this->hasMany(Voiture::class, 'comp_id', 'comp_id');
    }

    /**
     * Relation avec les chauffeurs
     */
    public function chauffeurs()
    {
        return $this->hasMany(Chauffeur::class, 'comp_id', 'comp_id');
    }

    /**
     * Scope pour les compagnies actives
     */
    public function scopeActive($query)
    {
        return $query->where('comp_statut', 1);
    }

    /**
     * Scope pour les compagnies inactives
     */
    public function scopeInactive($query)
    {
        return $query->where('comp_statut', 2);
    }
}