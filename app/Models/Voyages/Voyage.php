<?php

namespace App\Models\Voyages;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Trajet\Trajet;
use App\Models\Voitures\Voitures;
use App\Models\Reservation\Reservation;

class Voyage extends Model 
{
    use HasFactory;

    protected $table = 'fandrio_app.voyages';
    protected $primaryKey = 'voyage_id';

    protected $fillable = [
        'voyage_date',
        'voyage_heure_depart',
        'voyage_type',
        'traj_id',
        'voit_id',
        'voyage_statut',
        'places_disponibles',
        'places_reservees'
    ];

    protected $casts = [
        'voyage_date' => 'date',
        'voyage_heure_depart' => 'string',
        'places_disponibles' => 'integer',
        'places_reservees' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];


    /**
     * Relation avec le trajet
     */
    public function trajet() {
        return $this->belongsTo(Trajet::class, 'traj_id', 'traj_id');
    }

    /**
     * Relation avec la voiture
     */
    public function voiture() {
        return $this->belongsTo(Voitures::class, 'voit_id', 'voit_id');
    }

    /**
     * Relation avec les réservations
     */
    public function reservations() {
        return $this->hasMany(Reservation::class, 'voyage_id', 'voyage_id');
    }

    /**
     * Scope pour les futurs voyages
     */
    public function scopeFutur($query) {
        return $query->where('voyage_date', '>=', now()->toDateString())
                     ->where('voyage_statut', 1); // programé
    }

    /**
     * Scope pour les voyages d'une compagnie
     */
    public function scopeCompagnie($query, $compagnieId) {
        return $query->whereHas('trajet', function($q) use ($compagnieId) {
            $q->where('comp_id', $compagnieId);
        });
    }

    /**
     * Vérifie si le voyage est complet
     */
    public function estComplet(): bool
    {
        return $this->places_reservees >= $this->places_disponibles;
    }

    /**
     * Nombre de places disponibles
     */
    public function getPlacesLibres(): int
    {
        return $this->places_disponibles - $this->places_reservees;
    }

    /**
     * Vérifie si le voyage est dans le futur
     */
    public function estFutur(): bool
    {
        $dateTimeDepart = $this->voyage_date . ' ' . $this->voyage_heure_depart;
        return strtotime($dateTimeDepart) >= time();
    }

    /**
     * Peut être annulé (au moins 24h avant le départ)
     */
    public function peutEtreAnnule(): bool
    {
        $dateTimeDepart = strtotime($this->voyage_date . ' ' . $this->voyage_heure_depart);
        $timestampDepart = strtotime($dateTimeDepart);
        return ($timestampDepart - time()) >= 86400; // 24 heures en secondes
    }
}