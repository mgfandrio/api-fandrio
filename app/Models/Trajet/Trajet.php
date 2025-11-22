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
        'tra_duree',
        'comp_id',
        'traj_statut'
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
        if(!$this->traj_duuree) return '';

        try {
            $interval = new \DateInterval($this->traj_duree);
            $hours = $interval->h;
            $minutes = $interval->i;
            
            if ($hours > 0 && $minutes > 0) {
                return "{$hours}h {$minutes}min";

            } elseif ($hours > 0) {
                return "{$hours}h";

            } else {
                return "{$minutes}min";
            }

        }catch (\Exception $e) {
            return $this->traj_duree;
        }
    }
}