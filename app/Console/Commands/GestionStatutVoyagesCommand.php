<?php

namespace App\Console\Commands;

use App\Models\Notifications\Notification;
use App\Models\Reservation\Reservation;
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

        $clientsNotifies = 0;

        foreach ($voyagesAnnulesPeuRes as $voyage) {
            $voyage->update([
                'voyage_statut' => 4,
                'voyage_is_active' => false,
            ]);

            $voyageInfo = $this->formaterVoyageInfo($voyage);

            // Notifier la compagnie
            $compId = $voyage->trajet->comp_id ?? null;
            if ($compId) {
                NotificationService::notifierVoyageAnnule($compId, $voyageInfo, (int)$voyage->places_reservees);
            }

            // Notifier chaque client de l'annulation + passer leur réservation à statut 4
            // + Marquer le remboursement en attente (uniquement si paiement reçu)
            $reservations = Reservation::where('voyage_id', $voyage->voyage_id)
                ->whereIn('res_statut', [1, 2])
                ->get();

            foreach ($reservations as $reservation) {
                $aPaye = (float) $reservation->montant_avance > 0;
                $reservation->update([
                    'res_statut' => 4,
                    'res_remb_statut' => $aPaye ? 1 : 0, // 1 = en attente si paiement reçu
                    'res_remb_montant' => $aPaye ? $reservation->montant_avance : null,
                ]);
                NotificationService::notifierClientVoyageAnnule(
                    $reservation->util_id,
                    $reservation->res_id,
                    $voyageInfo
                );
                $clientsNotifies++;
            }
        }

        if ($clientsNotifies > 0) {
            $this->info("→ {$clientsNotifies} client(s) notifié(s) de l'annulation (réservations passées à statut 4)");
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
