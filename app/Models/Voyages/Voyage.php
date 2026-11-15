<?php

namespace App\Models\Voyages;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Trajet\Trajet;
use App\Models\Voitures\Voitures;
use App\Models\Reservation\Reservation;

class Voyage extends Model 
{
    /** Toute création / modification / suppression rafraîchit le fil d'accueil des clients. */
    protected static function booted(): void
    {
        static::saved(fn() => \App\Services\Client\AccueilService::invalider());
        static::deleted(fn() => \App\Services\Client\AccueilService::invalider());
    }

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
        'voyage_is_active',
        'voyage_publie',
        'voyage_publication_prevue',
        // Suivi de la voiture (en route / arrivé)
        'comp_reception',
        'voyage_suivi',
        'voyage_depart_le',
        'voyage_depart_auto',
        'voyage_arrivee_le',
        'voyage_arrivee_par',
        'voyage_retard_notifie',
        'places_disponibles',
        'places_reservees'
    ];

    protected $casts = [
        'voyage_date' => 'date',
        'voyage_heure_depart' => 'string',
        'voyage_is_active' => 'boolean',
        'voyage_publie' => 'boolean',
        'voyage_publication_prevue' => 'datetime',
        'comp_reception' => 'integer',
        'voyage_suivi' => 'integer',
        'voyage_depart_le' => 'datetime',
        'voyage_depart_auto' => 'boolean',
        'voyage_arrivee_le' => 'datetime',
        'voyage_arrivee_par' => 'integer',
        'voyage_retard_notifie' => 'boolean',
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

    /** Compagnie partenaire qui accueille la voiture à destination (null = suivi interne). */
    public function compagnieReception() {
        return $this->belongsTo(\App\Models\Compagnies\Compagnie::class, 'comp_reception', 'comp_id');
    }

    /** Suivi de la voiture : null = pas encore partie. */
    public const SUIVI_EN_ROUTE = 1;
    public const SUIVI_ARRIVE = 2;

    /**
     * Relation avec les réservations
     */
    public function reservations() {
        return $this->hasMany(Reservation::class, 'voyage_id', 'voyage_id');
    }

    /**
     * Catégorie du voyage dérivée du trajet (classique|vip|premium).
     * Retourne 'classique' par défaut si le trajet n'est pas chargé.
     */
    public function getVoyageCategorieAttribute(): string
    {
        return $this->trajet?->traj_categorie ?? 'classique';
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
        $depart = $this->getTimestampDepart();
        return $depart !== null && $depart >= time();
    }

    /**
     * Peut être annulé (au moins 24h avant le départ)
     */
    public function peutEtreAnnule(): bool
    {
        $depart = $this->getTimestampDepart();
        return $depart !== null && ($depart - time()) >= 86400; // 24 heures en secondes
    }

    /**
     * Timestamp du départ (date + heure).
     * voyage_date est casté en date (Carbon "Y-m-d 00:00:00") : le concaténer tel
     * quel avec l'heure donnait une chaîne invalide => strtotime() === false.
     */
    private function getTimestampDepart(): ?int
    {
        if (!$this->voyage_date || !$this->voyage_heure_depart) {
            return null;
        }

        $date = $this->voyage_date instanceof \DateTimeInterface
            ? $this->voyage_date->format('Y-m-d')
            : (string) $this->voyage_date;

        $timestamp = strtotime($date . ' ' . $this->voyage_heure_depart);

        return $timestamp === false ? null : $timestamp;
    }
}