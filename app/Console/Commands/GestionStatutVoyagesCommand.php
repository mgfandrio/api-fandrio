<?php

namespace App\Console\Commands;

use App\Models\Notifications\Notification;
use App\Models\Voyages\Voyage;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;

class GestionStatutVoyagesCommand extends Command
{
    protected $signature = 'voyages:gestion-statuts';
    protected $description = 'Met à jour automatiquement les statuts des voyages : en cours, terminé, annulé avec notifications';

    private const SEUIL_RESERVATIONS = 5;

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

        // 3. Programmé/En cours → Terminé : échéance atteinte ET >= 5 réservations
        $voyagesTermines = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee'])
            ->whereIn('voyage_statut', [1, 2])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('voyage_date', '<', $today)
                  ->orWhere(function ($q2) use ($today, $currentTime) {
                      $q2->where('voyage_date', $today)
                         ->where('voyage_heure_depart', '<=', $currentTime);
                  });
            })
            ->where('places_reservees', '>=', self::SEUIL_RESERVATIONS)
            ->get();

        foreach ($voyagesTermines as $voyage) {
            $voyage->update([
                'voyage_statut' => 3,
                'voyage_is_active' => false,
            ]);

            // Notifier la compagnie
            $compId = $voyage->trajet->comp_id ?? null;
            if ($compId) {
                $voyageInfo = $this->formaterVoyageInfo($voyage);

                // Vérifier qu'on n'a pas déjà envoyé cette notification
                $dejaNotifie = Notification::where('notif_type', 9)
                    ->where('notif_titre', 'Voyage arrivé à échéance')
                    ->where('notif_message', 'LIKE', "%{$voyage->voyage_id}%")
                    ->whereDate('created_at', $today)
                    ->exists();

                if (!$dejaNotifie) {
                    NotificationService::notifierVoyageTermine($compId, $voyageInfo, (int)$voyage->places_reservees);
                }
            }
        }

        if ($voyagesTermines->count() > 0) {
            $this->info("→ {$voyagesTermines->count()} voyage(s) terminé(s) (≥ " . self::SEUIL_RESERVATIONS . " réservations)");
        }

        // 4. Programmé/En cours → Annulé : échéance atteinte ET < 5 réservations (mais > 0)
        $voyagesAnnulesPeuRes = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee'])
            ->whereIn('voyage_statut', [1, 2])
            ->where(function ($q) use ($today, $currentTime) {
                $q->where('voyage_date', '<', $today)
                  ->orWhere(function ($q2) use ($today, $currentTime) {
                      $q2->where('voyage_date', $today)
                         ->where('voyage_heure_depart', '<=', $currentTime);
                  });
            })
            ->where('places_reservees', '>', 0)
            ->where('places_reservees', '<', self::SEUIL_RESERVATIONS)
            ->get();

        foreach ($voyagesAnnulesPeuRes as $voyage) {
            $voyage->update([
                'voyage_statut' => 4,
                'voyage_is_active' => false,
            ]);

            $compId = $voyage->trajet->comp_id ?? null;
            if ($compId) {
                $voyageInfo = $this->formaterVoyageInfo($voyage);
                NotificationService::notifierVoyageAnnule($compId, $voyageInfo, (int)$voyage->places_reservees);
            }
        }

        if ($voyagesAnnulesPeuRes->count() > 0) {
            $this->info("→ {$voyagesAnnulesPeuRes->count()} voyage(s) annulé(s) (< " . self::SEUIL_RESERVATIONS . " réservations, remboursement requis)");
        }

        // 5. Programmé → Annulé : date+heure passée ET aucune réservation
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

        $total = $complets + $enCours + $voyagesTermines->count() + $voyagesAnnulesPeuRes->count() + $annules;
        $this->info("Gestion statuts terminée : {$total} voyage(s) mis à jour.");

        return Command::SUCCESS;
    }

    /**
     * Formate les infos d'un voyage pour les notifications
     */
    private function formaterVoyageInfo(Voyage $voyage): string
    {
        $depart = $voyage->trajet?->provinceDepart?->pro_nom ?? 'N/A';
        $arrivee = $voyage->trajet?->provinceArrivee?->pro_nom ?? 'N/A';
        $date = $voyage->voyage_date?->format('d/m/Y') ?? '';
        $heure = $voyage->voyage_heure_depart ?? '';

        return "{$depart} → {$arrivee} le {$date}" . ($heure ? " à {$heure}" : '');
    }
}
