<?php

namespace App\Models\Voitures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voitures extends Model
{
    use HasFactory;

    protected $primaryKey = 'voit_id';
    protected $table = 'fandrio_app.voitures';

    protected $fillable = [
        'voit_matricule',
        'voit_marque',
        'voit_modele',
        'voit_places',
        'voit_statut',
        'comp_id',
        'chauff_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}
