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

        // Nouveau format simplifié : {"sieges": [1, 2, 3, ...]}
        if (isset($config['sieges']) && is_array($config['sieges'])) {
            foreach ($config['sieges'] as $numero) {
                $sieges[] = [
                    'code' => (string) $numero,
                    'rangee' => null,
                    'numero' => $numero,
                    'type' => 'normal',
                    'statut' => 'libre'
                ];
            }
            return $sieges;
        }

        // Ancien format avec rangées
        foreach ($config['rangees'] ?? [] as $rangee) {
            $lettreRangee = $rangee['lettre'] ?? null;
            foreach ($rangee['sieges'] as $numero => $type) {
                $sieges[] = [
                    'code' => (string) $numero,
                    'rangee' => $lettreRangee,
                    'numero' => $numero,
                    'type' => $type,
                    'statut' => 'libre'
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
        
        if (isset($config['sieges']) && is_array($config['sieges'])) {
            return count($config['sieges']);
        }

        $total = 0;
        foreach ($config['rangees'] ?? [] as $rangee) {
            $total += count($rangee['sieges'] ?? []);
        }

        return $total;
    }

}