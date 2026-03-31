<?php

namespace App\Models\Commissions;

use App\Models\Compagnies\Compagnie;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Database\Eloquent\Model;

class Collecte extends Model
{
    protected $table = 'fandrio_app.collectes';
    protected $primaryKey = 'coll_id';

    protected $fillable = [
        'comp_id',
        'coll_periode_debut',
        'coll_periode_fin',
        'coll_montant_brut',
        'coll_montant_commission',
        'coll_taux',
        'coll_nb_reservations',
        'coll_nb_billets',
        'coll_statut',
        'coll_date_prevue',
        'coll_date_confirmation',
        'coll_confirme_par',
    ];

    protected $casts = [
        'coll_periode_debut'     => 'date',
        'coll_periode_fin'       => 'date',
        'coll_date_prevue'       => 'date',
        'coll_date_confirmation' => 'datetime',
        'coll_montant_brut'      => 'decimal:2',
        'coll_montant_commission' => 'decimal:2',
        'coll_taux'              => 'decimal:2',
        'coll_nb_reservations'   => 'integer',
        'coll_nb_billets'        => 'integer',
        'coll_statut'            => 'integer',
    ];

    // Statuts
    const EN_ATTENTE = 1;
    const CONFIRMEE  = 2;

    public function compagnie()
    {
        return $this->belongsTo(Compagnie::class, 'comp_id', 'comp_id');
    }

    public function confirmePar()
    {
        return $this->belongsTo(Utilisateur::class, 'coll_confirme_par', 'util_id');
    }

    public function scopeEnAttente($query)
    {
        return $query->where('coll_statut', self::EN_ATTENTE);
    }

    public function scopeConfirmee($query)
    {
        return $query->where('coll_statut', self::CONFIRMEE);
    }

    public function scopeDueAujourdhui($query)
    {
        return $query->where('coll_date_prevue', '<=', today())
                     ->where('coll_statut', self::EN_ATTENTE);
    }
}
