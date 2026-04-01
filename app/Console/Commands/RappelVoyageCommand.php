<?php

namespace App\Console\Commands;

use App\Models\Notifications\Notification;
use App\Models\Reservation\Reservation;
use App\Models\Voyages\Voyage;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RappelVoyageCommand extends Command
{
    protected $signature = 'voyages:rappels';
    protected $description = 'Envoyer des rappels aux clients et des avertissements aux compagnies pour les voyages à venir';

    private const SEUIL_RESERVATIONS = 5;

    public function handle(): int
    {
        $today = Carbon::today();

        // ── 1. Rappels clients : J-2, J-1, J-0 ──
        $rappels = [
            ['date' => $today->copy()->addDays(2), 'jours' => 2],
            ['date' => $today->copy()->addDay(), 'jours' => 1],
            ['date' => $today, 'jours' => 0],
        ];

        $totalEnvoyes = 0;

        foreach ($rappels as $rappel) {
            $reservations = Reservation::where('res_statut', 2) // confirmées
                ->whereHas('voyage', function ($q) use ($rappel) {
                    $q->where('voyage_date', $rappel['date']->toDateString())
                      ->whereIn('voyage_statut', [1, 2]); // programmé ou en cours
                })
                ->with(['voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee', 'utilisateur'])
                ->get();

            foreach ($reservations as $reservation) {
                $voyage = $reservation->voyage;
                $voyageInfo = $this->formaterVoyageInfo($voyage);

                // Vérifier qu'on n'a pas déjà envoyé ce rappel
                $dejaEnvoye = Notification::where('res_id', $reservation->res_id)
                    ->where('notif_type', 2) // rappel
                    ->where('notif_destinataire_id', $reservation->util_id)
                    ->whereDate('created_at', $today)
                    ->exists();

                if (!$dejaEnvoye) {
                    NotificationService::envoyerRappelVoyage(
                        $reservation->util_id,
                        $reservation->res_id,
                        $voyageInfo,
                        $rappel['jours']
                    );
                    $totalEnvoyes++;
                }
            }
        }

        $this->info("Rappels clients envoyés : {$totalEnvoyes}");

        // ── 2. Avertissement J-1 : compagnies avec voyages < 5 réservations ──
        $demain = $today->copy()->addDay();
        $voyagesRisque = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee'])
            ->where('voyage_date', $demain->toDateString())
            ->whereIn('voyage_statut', [1, 2])
            ->where('places_reservees', '>', 0)
            ->where('places_reservees', '<', self::SEUIL_RESERVATIONS)
            ->get();

        $avertissementsEnvoyes = 0;

        foreach ($voyagesRisque as $voyage) {
            $compId = $voyage->trajet->comp_id ?? null;
            if (!$compId) continue;

            // Vérifier qu'on n'a pas déjà envoyé cet avertissement aujourd'hui
            $dejaAverti = Notification::where('notif_type', 11)
                ->where('notif_message', 'LIKE', "%{$voyage->voyage_id}%")
                ->whereDate('created_at', $today)
                ->exists();

            if (!$dejaAverti) {
                $voyageInfo = $this->formaterVoyageInfo($voyage);
                NotificationService::notifierAvertissementAnnulation(
                    $compId,
                    $voyageInfo,
                    (int)$voyage->places_reservees
                );
                $avertissementsEnvoyes++;
            }
        }

        if ($avertissementsEnvoyes > 0) {
            $this->info("Avertissements annulation envoyés : {$avertissementsEnvoyes} compagnie(s)");
        }

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
        $heure = $voyage->voyage_heure_depart ? ' à ' . $voyage->voyage_heure_depart : '';

        return "{$depart} → {$arrivee} le {$date}{$heure}";
    }
}
