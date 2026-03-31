<?php

namespace App\Models\Commissions;

use App\Models\Compagnies\Compagnie;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $table = 'fandrio_app.commissions';
    protected $primaryKey = 'comm_id';

    public $timestamps = false;

    protected $fillable = [
        'comp_id',
        'comm_periode',
        'nb_reservations',
        'nb_groupes_5',
        'comm_taux',
        'comm_montant',
        'comm_statut',
        'date_calcul',
    ];

    protected $casts = [
        'comm_taux'   => 'decimal:2',
        'comm_montant' => 'decimal:2',
        'comm_statut'  => 'integer',
        'nb_reservations' => 'integer',
        'nb_groupes_5'    => 'integer',
        'date_calcul'     => 'datetime',
    ];

    // ── Statuts ──
    const STATUT_CALCULEE = 1;
    const STATUT_FACTUREE = 2;
    const STATUT_PAYEE    = 3;

    public static function statutLabel(int $statut): string
    {
        return match ($statut) {
            self::STATUT_CALCULEE => 'Calculée',
            self::STATUT_FACTUREE => 'Facturée',
            self::STATUT_PAYEE    => 'Payée',
            default               => 'Inconnu',
        };
    }

    // ── Relations ──

    public function compagnie()
    {
        return $this->belongsTo(Compagnie::class, 'comp_id', 'comp_id');
    }

    // ── Scopes ──

    public function scopeCalculee($query)
    {
        return $query->where('comm_statut', self::STATUT_CALCULEE);
    }

    public function scopeFacturee($query)
    {
        return $query->where('comm_statut', self::STATUT_FACTUREE);
    }

    public function scopePayee($query)
    {
        return $query->where('comm_statut', self::STATUT_PAYEE);
    }
}
