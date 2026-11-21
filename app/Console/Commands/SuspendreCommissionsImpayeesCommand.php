<?php

namespace App\Console\Commands;

use App\Models\Compagnies\Compagnie;
use App\Services\Commission\CommissionService;
use Illuminate\Console\Command;

/**
 * Chaque jour : rappel des commissions non versées, avertissement à 7 jours,
 * suspension au-delà de 14 jours, réactivation des compagnies redevenues à jour.
 */
class SuspendreCommissionsImpayeesCommand extends Command
{
    protected $signature = 'commissions:suspendre-impayees';
    protected $description = 'Rappels, avertissements et suspensions liés aux commissions FANDRIO non versées.';

    public function handle(CommissionService $service): int
    {
        $bilan = $service->envoyerRappels();
        $this->info("Rappels : {$bilan['rappels']}, avertissements : {$bilan['avertissements']}.");

        $enRetard = $service->compagniesEnRetard();

        // 1. Suspension
        $aSuspendre = Compagnie::whereIn('comp_id', $enRetard ?: [0])->where('comp_suspendu_commission', false)->get();
        foreach ($aSuspendre as $comp) {
            $comp->update(['comp_suspendu_commission' => true]);
            $service->notifierAdmins($comp->comp_id, 'Compte suspendu — commission non versée',
                "Votre commission FANDRIO n'est pas versée depuis plus de " . CommissionService::GRACE_DAYS . " jours. Vos voyages sont masqués aux clients jusqu'au versement, depuis Portefeuille.");
            $service->journaliser($comp->comp_id, 'suspension',
                'Compagnie suspendue : commission non versée depuis plus de ' . CommissionService::GRACE_DAYS . ' jours. Voyages masqués aux clients.');
            $this->info("→ Suspendue : {$comp->comp_nom}");
        }

        // 2. Réactivation des compagnies qui ne sont plus en retard
        $aReactiver = Compagnie::where('comp_suspendu_commission', true)->whereNotIn('comp_id', $enRetard ?: [0])->get();
        foreach ($aReactiver as $comp) {
            $service->reactiverCompagnieSiAJour($comp->comp_id);
            $service->notifierAdmins($comp->comp_id, 'Compte réactivé',
                'Votre commission est à jour : vos voyages sont de nouveau visibles des clients.');
            $this->info("→ Réactivée : {$comp->comp_nom}");
        }

        $this->info("Terminé : {$aSuspendre->count()} suspendue(s), {$aReactiver->count()} réactivée(s).");

        return Command::SUCCESS;
    }
}
