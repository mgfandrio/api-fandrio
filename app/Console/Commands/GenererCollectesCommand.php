<?php

namespace App\Console\Commands;

use App\Models\Commissions\Collecte;
use App\Models\Compagnies\Compagnie;
use App\Models\Reservation\Reservation;
use App\Models\Voyages\Voyage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenererCollectesCommand extends Command
{
    protected $signature = 'commissions:generer-collectes';
    protected $description = 'Génère automatiquement les collectes de commission en attente pour les compagnies actives';

    private float $tauxCommission = 0.05;

    public function handle(): int
    {
        $today = now();
        $jourSemaine = strtolower($today->locale('fr')->dayName); // lundi, mardi, etc.
        $jourMois = $today->day;

        $compagnies = Compagnie::where('comp_statut', 1)
            ->where('comm_actif', true)
            ->get();

        $nbGenerees = 0;

        foreach ($compagnies as $compagnie) {
            $frequence = $compagnie->comm_frequence_collecte ?? 'mensuelle';
            $jourCollecte = $compagnie->comm_jour_collecte;

            // Vérifier si c'est le jour de collecte
            if (!$this->estJourCollecte($frequence, $jourCollecte, $jourSemaine, $jourMois)) {
                continue;
            }

            // Calculer la période précédente
            [$periodeDebut, $periodeFin] = $this->calculerPeriodePrecedente($frequence, $today);

            // Vérifier qu'une collecte n'existe pas déjà pour cette période
            $existe = Collecte::where('comp_id', $compagnie->comp_id)
                ->where('coll_periode_debut', $periodeDebut)
                ->where('coll_periode_fin', $periodeFin)
                ->exists();

            if ($existe) {
                continue;
            }

            // Calculer les montants pour la période
            $voyageIds = Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnie->comp_id))
                ->pluck('voyage_id');

            $reservations = Reservation::whereIn('voyage_id', $voyageIds)
                ->where('res_statut', 2)
                ->whereBetween('created_at', [$periodeDebut, $periodeFin->copy()->endOfDay()])
                ->select(
                    DB::raw('COALESCE(SUM(montant_total), 0) as brut'),
                    DB::raw('COALESCE(SUM(nb_voyageurs), 0) as billets'),
                    DB::raw('COUNT(*) as nb_reservations')
                )
                ->first();

            $montantBrut = (float)$reservations->brut;
            $montantCommission = round($montantBrut * $this->tauxCommission, 2);

            // Ne créer la collecte que s'il y a des réservations
            if ((int)$reservations->nb_reservations === 0) {
                continue;
            }

            Collecte::create([
                'comp_id'                 => $compagnie->comp_id,
                'coll_periode_debut'      => $periodeDebut,
                'coll_periode_fin'        => $periodeFin,
                'coll_montant_brut'       => $montantBrut,
                'coll_montant_commission' => $montantCommission,
                'coll_taux'              => $this->tauxCommission * 100,
                'coll_nb_reservations'    => (int)$reservations->nb_reservations,
                'coll_nb_billets'         => (int)$reservations->billets,
                'coll_statut'            => Collecte::EN_ATTENTE,
                'coll_date_prevue'       => $today->toDateString(),
            ]);

            $nbGenerees++;
            $this->info("→ Collecte générée pour {$compagnie->comp_nom} ({$periodeDebut->format('d/m/Y')} - {$periodeFin->format('d/m/Y')})");
        }

        if ($nbGenerees === 0) {
            $this->info('Aucune collecte à générer aujourd\'hui.');
        } else {
            $this->info("✓ {$nbGenerees} collecte(s) générée(s) avec succès.");
        }

        return Command::SUCCESS;
    }

    /**
     * Vérifie si aujourd'hui est un jour de collecte pour la compagnie
     */
    private function estJourCollecte(string $frequence, ?string $jourCollecte, string $jourSemaine, int $jourMois): bool
    {
        if ($frequence === 'hebdomadaire') {
            // Si aucun jour défini, par défaut lundi
            $jourAttendu = $jourCollecte ?: 'lundi';
            return $jourSemaine === strtolower($jourAttendu);
        }

        if ($frequence === 'mensuelle') {
            // Si aucun jour défini, par défaut le 1er
            $dateAttendue = $jourCollecte ? (int)$jourCollecte : 1;
            // Gérer le cas où le jour dépasse les jours du mois
            $dernierJour = now()->daysInMonth;
            if ($dateAttendue > $dernierJour) {
                return $jourMois === $dernierJour;
            }
            return $jourMois === $dateAttendue;
        }

        return false;
    }

    /**
     * Calcule la période précédente selon la fréquence
     */
    private function calculerPeriodePrecedente(string $frequence, $today): array
    {
        if ($frequence === 'hebdomadaire') {
            // La semaine précédente (lundi → dimanche)
            $finPeriode = $today->copy()->subWeek()->endOfWeek();
            $debutPeriode = $finPeriode->copy()->startOfWeek();
            return [$debutPeriode, $finPeriode];
        }

        // Mensuelle : le mois précédent
        $debutPeriode = $today->copy()->subMonth()->startOfMonth();
        $finPeriode = $today->copy()->subMonth()->endOfMonth();
        return [$debutPeriode, $finPeriode];
    }
}
