<?php

namespace App\Models\Chauffeurs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Chauffeurs\Chauffeurs;
use App\Models\Compagnies\Compagnie;


class Chauffeurs extends Model
{
    use HasFactory;
    
    protected $table = 'fandrio_app.chauffeurs';
    // protected $schema = 'fandrio_app';
    protected $primaryKey = 'chauff_id';
    
    protected $fillable = [
        'chauff_nom',
        'chauff_prenom',
        'chauff_age',
        'chauff_cin',
        'chauff_permis',
        'chauff_phone',
        'chauff_statut',
        'chauff_photo',
        'comp_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}
