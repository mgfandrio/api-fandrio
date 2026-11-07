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

        // Format par rangées (saisie manuelle : liste de types ; génération
        // automatique : liste d'objets {numero, lettre, type}).
        // Les sièges sont numérotés en ENTIERS de 1 à N, dans l'ordre des
        // rangées. Auparavant le code était l'index du siège DANS sa rangée
        // (0, 1, 2, 3, 0, 1, ...) : codes en double d'une rangée à l'autre et
        // code 0 refusé par reservation_voyageurs.place_numero (> 0).
        $numero = 0;
        foreach ($config['rangees'] ?? [] as $rangee) {
            $rangeeId = $rangee['lettre'] ?? $rangee['numero'] ?? null;
            foreach ($rangee['sieges'] ?? [] as $siege) {
                $numero++;
                $sieges[] = [
                    'code' => (string) $numero,
                    'rangee' => $rangeeId,
                    'numero' => $numero,
                    'type' => is_array($siege) ? ($siege['type'] ?? 'normal') : $siege,
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