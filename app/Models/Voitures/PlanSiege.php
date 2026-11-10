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
     * Cases d'une grille dessinée par la compagnie (config_sieges.grille) :
     *   'C' = chauffeur, 'S' = siège, '-' = vide ou couloir.
     * Les sièges sont numérotés 1..N de l'avant vers l'arrière, de gauche à droite.
     */
    public const CASE_CHAUFFEUR = 'C';
    public const CASE_SIEGE = 'S';
    public const CASE_VIDE = '-';

    /** Grille dessinée, ou null pour un plan automatique (ancien format). */
    public function getGrille(): ?array
    {
        $grille = $this->config_sieges['grille'] ?? null;
        return is_array($grille) && !empty($grille) ? $grille : null;
    }

    /**
     * Grille telle qu'affichée par l'application : 'C' (chauffeur), '' (vide)
     * ou le numéro du siège. Null si le plan n'a pas de grille.
     */
    public function getGrilleAvecNumeros(): ?array
    {
        $grille = $this->getGrille();
        if ($grille === null) {
            return null;
        }
        $numero = 0;
        $resultat = [];
        foreach ($grille as $rangee) {
            $ligne = [];
            foreach ($rangee as $case) {
                $ligne[] = $case === self::CASE_SIEGE
                    ? (string) ++$numero
                    : ($case === self::CASE_CHAUFFEUR ? 'C' : '');
            }
            $resultat[] = $ligne;
        }
        return $resultat;
    }

    /**
     * Parse la configuration des sièges
     */
    public function getSiegesParses(): array 
    {
        $config = $this->config_sieges ?? [];
        $sieges = [];

        // Grille dessinée par la compagnie
        if ($grille = $this->getGrille()) {
            $numero = 0;
            foreach ($grille as $r => $rangee) {
                foreach ($rangee as $case) {
                    if ($case !== self::CASE_SIEGE) {
                        continue;
                    }
                    $numero++;
                    $sieges[] = [
                        'code' => (string) $numero,
                        'rangee' => $r + 1,
                        'numero' => $numero,
                        'type' => 'standard',
                        'statut' => 'libre'
                    ];
                }
            }
            return $sieges;
        }

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
        if ($this->getGrille()) {
            return count($this->getSiegesParses());
        }
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