<?php

namespace App\Console\Commands;

use App\Models\Voyages\Voyage;
use Illuminate\Console\Command;

class GestionStatutVoyagesCommand extends Command
{
    protected $signature = 'voyages:gestion-statuts';
    protected $description = 'Met à jour automatiquement les statuts des voyages : en cours, terminé, annulé (sans réservation)';

    public function handle(): int
    {
        $now = now();
        $today = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        // 1. Programmé → En cours : places toutes réservées
        $complets = Voyage::where('voyage_statut', 1)
            ->whereRaw('places_reservees >= places_disponibles')
            ->update(['voyage_statut' => 2]);

        if ($complets > 0) {
            $this->info("→ {$complets} voyage(s) complet(s) passé(s) en cours");
        }

        // 2. Programmé → En cours : heure de départ atteinte aujourd'hui
        $enCours = Voyage::where('voyage_statut', 1)
            ->where('voyage_date', $today)
            ->where('voyage_heure_depart', '<=', $currentTime)
            ->update(['voyage_statut' => 2]);

        if ($enCours > 0) {
            $this->info("→ {$enCours} voyage(s) démarré(s) passé(s) en cours");
        }

        // 3. Programmé/En cours → Terminé : date passée ET a des réservations
        $termines = Voyage::whereIn('voyage_statut', [1, 2])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('voyage_date', '<', $today)
                  ->orWhere(function ($q2) use ($today, $currentTime) {
                      $q2->where('voyage_date', $today)
                         ->where('voyage_heure_depart', '<=', $currentTime);
                  });
            })
            ->where('places_reservees', '>', 0)
            ->update([
                'voyage_statut' => 3,
                'voyage_is_active' => false,
            ]);

        if ($termines > 0) {
            $this->info("→ {$termines} voyage(s) terminé(s)");
        }

        // 4. Programmé → Annulé : date+heure passée ET aucune réservation
        $annules = Voyage::where('voyage_statut', 1)
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('voyage_date', '<', $today)
                  ->orWhere(function ($q2) use ($today, $currentTime) {
                      $q2->where('voyage_date', $today)
                         ->where('voyage_heure_depart', '<=', $currentTime);
                  });
            })
            ->where('places_reservees', 0)
            ->update([
                'voyage_statut' => 4,
                'voyage_is_active' => false,
            ]);

        if ($annules > 0) {
            $this->info("→ {$annules} voyage(s) sans réservation annulé(s)");
        }

        $total = $complets + $enCours + $termines + $annules;
        $this->info("Gestion statuts terminée : {$total} voyage(s) mis à jour.");

        return Command::SUCCESS;
    }
}
