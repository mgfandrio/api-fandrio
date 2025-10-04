<?php

namespace App\Models\Reservation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $connection = 'fandrio_app';
    protected $table = 'reservations';
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
        'type_paie_id'
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'util_id', 'util_id');
    }
}