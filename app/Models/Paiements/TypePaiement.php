<?php

namespace App\Models\Paiements;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TypePaiement extends Model
{
    use HasFactory;

    protected $table = 'fandrio_app.types_paiement';
    protected $primaryKey = 'type_paie_id';

    protected $fillable = [
        'type_paie_nom', 
        'type_paie_type', 
        'type_paie_devise', 
        'type_paie_statut'
    ];
}