<?php

namespace App\Console\Commands;

use App\Models\Commissions\Collecte;
use App\Models\Compagnies\Compagnie;
use App\Models\Utilisateurs\Utilisateur;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;

class SuspendreCommissionsImpayeesCommand extends Command
{
    protected $signature = 'commissions:suspendre-impayees';
    protected $description = 'Suspend les compagnies dont la commission est impayée au-delà du délai de grâce, et réactive celles à jour.';

    private const GRACE_DAYS = 3;

    public function handle(): int
    {
        // Une collecte est "en retard" si elle est en attente et que son échéance
        // est dépassée depuis plus que le délai de grâce.
        $limite = now()->subDays(self::GRACE_DAYS)->toDateString();

        $compEnRetard = Collecte::where('coll_statut', Collecte::EN_ATTENTE)
            ->whereDate('coll_date_prevue', '<', $limite)
            ->distinct()
            ->pluck('comp_id')
            ->all();

        // 1. SUSPENSION : compagnies en retard, pas encore suspendues
        $aSuspendre = Compagnie::whereIn('comp_id', $compEnRetard ?: [0])
            ->where('comp_suspendu_commission', false)
            ->get();

        foreach ($aSuspendre as $comp) {
            $comp->update(['comp_suspendu_commission' => true]);
            $this->notifier($comp, true);
            $this->info("→ Suspendue : {$comp->comp_nom}");
        }

        // 2. RÉACTIVATION : compagnies suspendues qui ne sont plus en retard
        $aReactiver = Compagnie::where('comp_suspendu_commission', true)
            ->whereNotIn('comp_id', $compEnRetard ?: [0])
            ->get();

        foreach ($aReactiver as $comp) {
            $comp->update(['comp_suspendu_commission' => false]);
            $this->notifier($comp, false);
            $this->info("→ Réactivée : {$comp->comp_nom}");
        }

        $this->info("Terminé : {$aSuspendre->count()} suspendue(s), {$aReactiver->count()} réactivée(s).");

        return Command::SUCCESS;
    }

    /**
     * Notifie les admins (role 2) de la compagnie de la suspension / réactivation.
     * Un échec de notification ne bloque pas l'opération.
     */
    private function notifier(Compagnie $comp, bool $suspendu): void
    {
        $titre = $suspendu ? 'Compte suspendu — commission impayée' : 'Compte réactivé';
        $message = $suspendu
            ? "Votre compagnie est suspendue faute de paiement de la commission dans le délai imparti. Vos voyages sont masqués de la recherche client jusqu'au règlement de votre collecte."
            : "Votre commission est à jour : votre compagnie est réactivée et vos voyages sont de nouveau visibles par les clients.";

        $admins = Utilisateur::where('comp_id', $comp->comp_id)
            ->where('util_role', 2)
            ->where('util_statut', 1)
            ->get();

        foreach ($admins as $admin) {
            try {
                NotificationService::envoyer([
                    'type'              => 8,
                    'destinataire_type' => 2,
                    'destinataire_id'   => $admin->util_id,
                    'titre'             => $titre,
                    'message'           => $message,
                ]);
            } catch (\Throwable $e) {
                // notification best-effort : ne pas bloquer la suspension/réactivation
            }
        }
    }
}
