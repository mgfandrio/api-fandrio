<?php

namespace App\Console\Commands;

use App\Models\Compagnies\Compagnie;
use App\Services\Commission\CommissionService;
use Illuminate\Console\Command;

/**
 * Crée les tranches de commission dues : N voyages terminés atteints (5 par défaut),
 * ou 30 jours écoulés depuis le 1er voyage d'une tranche incomplète.
 */
class GenererCollectesCommand extends Command
{
    protected $signature = 'commissions:generer-collectes';
    protected $description = 'Crée les tranches de commission FANDRIO à verser (tous les N voyages terminés)';

    public function handle(CommissionService $service): int
    {
        $total = 0;

        Compagnie::where('comp_statut', 1)->where('comm_actif', true)->each(function (Compagnie $compagnie) use ($service, &$total) {
            foreach ($service->genererTranches($compagnie) as $c) {
                $total++;
                $this->info("→ {$compagnie->comp_nom} : {$c->coll_nb_voyages} voyage(s), {$service->ariary($c->coll_montant_commission)}");
            }
        });

        $this->info($total ? "✓ {$total} tranche(s) créée(s)." : 'Aucune tranche à créer.');

        return Command::SUCCESS;
    }
}
