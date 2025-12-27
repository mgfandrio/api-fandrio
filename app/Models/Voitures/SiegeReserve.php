<?php 

namespace App\Models\Voitures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Voyages\Voyage;
use App\Models\Reservation\Reservation;
use App\Models\Utilisateurs\Utilisateur;

class SiegeReserve extends Model 
{
    use HasFactory;

    protected $table = 'fandrio_app.sieges_reserves';
    protected $primaryKey = 'sieges_id';

    protected $fillable = [
        'voyage_id',
        'siege_numero',
        'res_id',
        'siege_statut',
        'utilisateur_id',
        'expire_lock'
    ];

    protected $casts = [
        'expire_lock' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relation avec le voyage
     */
    public function voyage()
    {
        return $this->belongsTo(Voyage::class, 'voyage_id', 'voyage_id');
    }

    /**
     * Relation avec la réservation
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class, 'res_id', 'res_id');
    }

    /**
     * Relation avec l'utilisateur (pour lock temporaire)
     */
    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id', 'util_id');
    }

    /**
     * Vérifie si le lock temporaire est expiré
     */
    public function estLockExpire(): bool 
    {
        if (!$this->expire_lock || $this->siege_statut != 3) {
            return true;
        }

        return now()->greaterThan($this->expire_lock);
    }

    /***
     * Vérifie si le siège est disponible
     */
    public function estDisponible(): bool 
    {
        return $this->siege_statut == 2 ||
                ($this->siege_statut == 3 && $this->estLockExpire());
    }


    /**
     * Verrouille temporairement un siège
     */
    public function verrouillerTemporairement(int $utilisateurId, int $dureeSecondes = 300): bool 
    {
        if (!$this->estDisponible()) {
            return false;
        }

        $this->update([
            'siege_statut' => 3,
            'utilisateur_id' => $utilisateurId,
            'expire_lock' => now()->addSeconds($dureeSecondes)
        ]);

        return true;
    }


    /**
     *  Libère un siège verrouillé
     */
    public function liberer(): bool
    {
        if ($this->siege_statut == 3 ) {
            $this->update([
                'siege_statut' => 2,
                'utilisateur_id' => null,
                'expire_lock' => null
            ]);
            return true;
        }

        return false;
    }
}