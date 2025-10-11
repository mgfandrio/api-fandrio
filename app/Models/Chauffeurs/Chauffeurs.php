<?php

namespace App\Models\Chauffeurs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chauffeurs extends Model
{
    use HasFactory;

    protected $primaryKey = 'chauff_id';
    protected $table = 'fandrio_app.chauffeurs';

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
