<?php

namespace App\Console\Commands;

use App\Services\Voyage\SuiviVoyageService;
use Illuminate\Console\Command;

/**
 * Suivi des voitures en route :
 *  - passage automatique « en route » 1 h après l'heure de départ quand
 *    l'embarquement n'a pas été clôturé (la compagnie de réception est prévenue) ;
 *  - alerte de retard aux deux compagnies 1 h après l'arrivée prévue.
 * Planifiée chaque minute (app/Console/Kernel.php), après voyages:gestion-statuts.
 */
class SuiviVoyagesCommand extends Command
{
    protected $signature = 'voyages:suivi';

    protected $description = 'Départs automatiques et alertes de retard des voitures en route';

    public function handle(SuiviVoyageService $suivi): int
    {
        $r = $suivi->traiterAutomatique();

        if ($r['departs_auto'] > 0) {
            $this->info("→ {$r['departs_auto']} voyage(s) passé(s) en route automatiquement");
        }
        if ($r['retards'] > 0) {
            $this->info("→ {$r['retards']} alerte(s) de retard envoyée(s)");
        }

        return self::SUCCESS;
    }
}
