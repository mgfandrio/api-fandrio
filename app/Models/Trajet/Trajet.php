<?php

namespace App\Models\Trajet;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Compagnies\Compagnie;
use App\Models\Provinces\Province;
use App\Models\Voyages\Voyage;

class Trajet extends Model 
{
    use HasFactory;

    protected $table = 'fandrio_app.trajets';
    protected $primaryKey = 'traj_id';

    protected $fillable = [
        'traj_nom',
        'pro_depart',
        'pro_arrivee',
        'traj_tarif',
        'traj_km',
        'traj_duree',
        'comp_id',
        'traj_statut',
        'traj_categorie'
    ];

    protected $casts = [
        'traj_tarif'    => 'decimal:2',
        'traj_km'       => 'integer',
        'traj_duree'    => 'string',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime'
    ];

    /**
     * Relation avec la compagnie propriétaire
     */
    public function compagnie() {
        return $this->belongsTo(Compagnie::class, 'comp_id', 'comp_id');
    }

    /**
     * Relation avec la province de départ
     */
    public function provinceDepart() {
        return $this->belongsTo(Province::class, 'pro_depart', 'pro_id');
    }

    /**
     * Relation avec la province d'arrivée
     */
    public function provinceArrivee() {
        return $this->belongsTo(Province::class, 'pro_arrivee', 'pro_id');
    }

    /**
     * Relation avec les voyages programmés
     */
    public function voyages() {
        return $this->hasMany(Voyage::class, 'traj_id', 'traj_id');
    }

    /**
     * Scope pour les trajets d'une compagnie spécifique
     */
    public function scopeDeCompagnie($query, $compagnieId) {
        return $query->where('comp_id', $compagnieId);
    }

    /**
     * Vérifie si le trajet est actif
     */
    public function estActif(): bool {
        return $this->traj_statut === 1; 
    }

    /**
     * Calcule la durée formatée
     */
    public function getDureeFormatee(): string
    {
        // traj_duree est un INTERVAL PostgreSQL : « 23:00:00 », « 08:30:00 » ou « 1 day 02:00:00 »
        if (!$this->traj_duree) return '';

        if (!preg_match('/^(?:(\d+)\s+days?\s*)?(\d+):(\d{2})/', trim((string) $this->traj_duree), $m)) {
            return (string) $this->traj_duree;
        }
        $heures = ((int) ($m[1] ?? 0)) * 24 + (int) $m[2];
        $minutes = (int) $m[3];

        if ($heures > 0 && $minutes > 0) return "{$heures}h {$minutes}min";
        if ($heures > 0) return "{$heures}h";
        return "{$minutes}min";
    }
}