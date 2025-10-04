<?php

namespace App\Models\Voyageur;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Database\Eloquent\Model;

class Voyageur extends Model
{
    use HasFactory;

    protected $table = 'fandrio_app.voyageurs';
    protected $primaryKey = 'voya_id';

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
}