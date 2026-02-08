<?php

namespace App\Models\Reservation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Utilisateurs\Utilisateur;
use App\Models\Voyageur\Voyageur;
use App\Models\Voyages\Voyage;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $table = 'fandrio_app.reservations';
    protected $primaryKey = 'res_id';

    protected $fillable = [
        'res_numero',
        'util_id',
        'voyage_id',
        'res_statut',
        'nb_voyageurs',
        'montant_total',
        'montant_avance',
        'montant_restant',
        'type_paie_id',
        'numero_paiement',
        'date_limite_paiement',
        'date_annulation_possible'
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'util_id', 'util_id');
    }

    /**
     * Relation avec le voyage
     */
    public function voyage()
    {
        return $this->belongsTo(Voyage::class, 'voyage_id', 'voyage_id');
    }

    /**
     * Relation avec les entrées de reservation_voyageurs
     */
    public function reservationVoyageurs()
    {
        return $this->hasMany(ReservationVoyageur::class, 'res_id', 'res_id');
    }

    /**
     * Relation avec les voyageurs via la table pivot
     */
    public function voyageurs()
    {
        return $this->belongsToMany(
            Voyageur::class,
            'fandrio_app.reservation_voyageurs',
            'res_id',
            'voya_id',
            'res_id',
            'voya_id'
        )->withPivot('place_numero', 'res_voya_statut');
    }
}