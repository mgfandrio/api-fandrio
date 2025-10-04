<?php

namespace App\Models\Utilisateurs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Utilisateur extends Authenticatable implements JWTSubject
{
    use HasFactory;

    protected $table = 'fandrio_app.utilisateurs';
    protected $primaryKey = 'util_id';

    /**
     * Les champs qui peuvent être assignés en masse.
     */
    protected $fillable = [
        'util_nom',
        'util_prenom',
        'util_email',
        'util_phone',
        'util_password',
        'util_role',
        'comp_id',
        'util_statut'
    ];

    /**
     * Les champs qui doivent être cachés pour les sérialisations.
     */
    protected $hidden = [
        'util_password',
        'remember_token',
    ];

    /**
     * Get the password for the user.
     */
    public function getAuthPassword()
    {
        return $this->util_password;
    }

    /**
     * Get the identifier that will be stored in the JWT.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     */
    public function getJWTCustomClaims()
    {
        return [
            'util_role' => $this->util_role,
            'comp_id' => $this->comp_id,
            'util_email' => $this->util_email
        ];
    }

    /**
     * Relation avec la compagnie (pour les administrateurs de compagnie)
     */
    public function compagnie()
    {
        return $this->belongsTo(Compagnie::class, 'comp_id', 'comp_id');
    }

    /**
     * Vérifie si l'utilisateur est un administrateur système
     */
    public function estAdminSysteme()
    {
        return $this->util_role === 3;
    }

    /**
     * Vérifie si l'utilisateur est un administrateur de compagnie
     */
    public function estAdminCompagnie()
    {
        return $this->util_role === 2;
    }

    /**
     * Vérifie si l'utilisateur est un client
     */
    public function estClient()
    {
        return $this->util_role === 1;
    }

    /**
     * Vérifie si le compte est actif
     */
    public function estActif()
    {
        return $this->util_statut === 1;
    }

    /**
     * Relation avec les voyageurs (passagers saisis par l'utilisateur)
     */
    public function voyageurs()
    {
        return $this->hasMany(Voyageur::class, 'util_id', 'util_id');
    }

    /**
     * Relation avec les réservations
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'util_id', 'util_id');
    }
}