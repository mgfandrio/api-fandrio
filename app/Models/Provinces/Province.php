<?php

namespace App\Models\Provinces;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    use HasFactory;

    protected $table = 'fandrio_app.provinces';
    protected $primaryKey = 'pro_id';

    protected $fillable = ['pro_nom', 'pro_orientation'];
}