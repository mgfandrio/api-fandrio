<?php

namespace App\Models\Voyageur;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Utilisateurs\Utilisateur;
use App\Models\Reservation\Reservation;
use App\Models\Reservation\ReservationVoyageur;
use Illuminate\Database\Eloquent\Model;

class Voyageur extends Model
{
    use HasFactory;

    protected $table = 'fandrio_app.voyageurs';
    protected $primaryKey = 'voya_id';
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'voya_nom',
        'voya_prenom',
        'voya_age',
        'voya_cin',
        'voya_phone',
        'voya_phone2',
        'util_id'
    ];

    /**
     * Relation avec l'utilisateur qui a saisi le voyageur
     */
    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'util_id', 'util_id');
    }

    /**
     * Relation avec les entrées de reservation_voyageurs
     */
    public function reservationVoyageurs()
    {
        return $this->hasMany(ReservationVoyageur::class, 'voya_id', 'voya_id');
    }

    /**
     * Relation avec les réservations via la table pivot
     */
    public function reservations()
    {
        return $this->belongsToMany(
            Reservation::class,
            'fandrio_app.reservation_voyageurs',
            'voya_id',
            'res_id',
            'voya_id',
            'res_id'
        )->withPivot('place_numero', 'res_voya_statut');
    }
}