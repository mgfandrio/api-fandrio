<?php

namespace App\Models\Reservation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Voyageur\Voyageur;
use Illuminate\Database\Eloquent\Model;

class ReservationVoyageur extends Model
{
    use HasFactory;

    protected $table = 'fandrio_app.reservation_voyageurs';
    protected $primaryKey = 'res_voya_id';
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'res_id',
        'voya_id',
        'place_numero',
        'res_voya_statut'
    ];

    /**
     * Relation avec la réservation
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class, 'res_id', 'res_id');
    }

    /**
     * Relation avec le voyageur
     */
    public function voyageur()
    {
        return $this->belongsTo(Voyageur::class, 'voya_id', 'voya_id');
    }
}
