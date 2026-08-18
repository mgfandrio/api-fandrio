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

            // Billets FACTURABLES : réservations payées de voyages TERMINÉS dont la date
            // tombe dans la période, commission déjà figée, et pas encore facturées
            // (coll_id NULL → composition immuable, jamais facturé deux fois).
            $billables = Reservation::where('res_statut', 2)
                ->whereNull('coll_id')
                ->whereNotNull('res_commission')
                ->whereHas('voyage', function ($q) use ($compagnie, $periodeDebut, $periodeFin) {
                    $q->where('voyage_statut', 3) // Terminé
                        ->whereBetween('voyage_date', [$periodeDebut->toDateString(), $periodeFin->toDateString()])
                        ->whereHas('trajet', fn($t) => $t->where('comp_id', $compagnie->comp_id));
                })
                ->get(['res_id', 'montant_total', 'nb_voyageurs', 'res_commission']);

            // Ne créer la collecte que s'il y a des billets à facturer
            if ($billables->isEmpty()) {
                continue;
            }

            // Total = SOMME des commissions FIGÉES par billet (réconcilie au centime avec le détail)
            $montantBrut       = round($billables->sum(fn($r) => (float) $r->montant_total), 2);
            $montantCommission = round($billables->sum(fn($r) => (float) $r->res_commission), 2);
            $nbBillets         = (int) $billables->sum(fn($r) => (int) $r->nb_voyageurs);
            $nbReservations    = $billables->count();

            DB::transaction(function () use ($compagnie, $periodeDebut, $periodeFin, $montantBrut, $montantCommission, $nbReservations, $nbBillets, $today, $billables) {
                $collecte = Collecte::create([
                    'comp_id'                 => $compagnie->comp_id,
                    'coll_periode_debut'      => $periodeDebut,
                    'coll_periode_fin'        => $periodeFin,
                    'coll_montant_brut'       => $montantBrut,
                    'coll_montant_commission' => $montantCommission,
                    'coll_taux'               => $this->tauxCommission * 100,
                    'coll_nb_reservations'    => $nbReservations,
                    'coll_nb_billets'         => $nbBillets,
                    'coll_statut'             => Collecte::EN_ATTENTE,
                    'coll_date_prevue'        => $today->toDateString(),
                    'coll_mode'               => Collecte::MODE_MANUEL,
                ]);

                // Tamponner les réservations facturées → composition immuable
                Reservation::whereIn('res_id', $billables->pluck('res_id'))
                    ->update(['coll_id' => $collecte->coll_id]);
            });

            $nbGenerees++;
            $this->info("→ Collecte pour {$compagnie->comp_nom} ({$periodeDebut->format('d/m/Y')} - {$periodeFin->format('d/m/Y')}) : {$montantCommission} Ar / {$nbReservations} réservation(s)");
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
