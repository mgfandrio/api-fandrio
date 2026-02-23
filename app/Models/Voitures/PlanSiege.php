<?php 

namespace App\Models\Voitures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Voitures\Voitures;

class PlanSiege extends Model
{
    use HasFactory;

    protected $table = 'fandrio_app.plan_sieges';
    protected $primaryKey = 'plan_id';

    protected $fillable = [
        'voit_id',
        'config_sieges',
        'plan_nom',
        'plan_statut'
    ];

    protected $casts = [
        'config_sieges' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];


    /**
     * Relation avec la voiture
     */
    public function voiture()
    {
        return $this->belongsTo(Voitures::class, 'voit_id', 'voit_id');
    }


    /**
     * Parse la configuration des sièges
     */
    public function getSiegesParses(): array 
    {
        $config = $this->config_sieges ?? [];
        $sieges = [];

        foreach ($config['rangees'] ?? [] as $rangee) {
            $lettreRangee = $rangee['lettre'];
            foreach ($rangee['sieges'] as $numero => $type) {
                $sieges[] = [
                    'code' => $lettreRangee . $numero,
                    'rangee' => $lettreRangee,
                    'numero' => $numero,
                    'type' => $type,// ''normal', 'couloir', 'fenetre'
                    'statut' => 'libre' // Par défaut
                ];
            }
        }
        return $sieges;
    }


    /**
     * Nombre total de sièges
     */
    public function getNombreTotalSieges(): int
    {
        $config = $this->config_sieges ?? [];
        $total = 0;

        foreach ($config['rangees'] ?? [] as $rangee) {
            $total += count($rangee['sieges']);
        }

        return $total;
    }

}