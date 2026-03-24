<?php

namespace App\Console\Commands;

use App\Models\Reservation\Reservation;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RappelVoyageCommand extends Command
{
    protected $signature = 'voyages:rappels';
    protected $description = 'Envoyer des rappels aux clients pour leurs voyages à venir (2 jours, 1 jour, jour J)';

    public function handle(): int
    {
        $today = Carbon::today();
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
                $depart = $voyage->trajet->provinceDepart->pro_nom ?? 'N/A';
                $arrivee = $voyage->trajet->provinceArrivee->pro_nom ?? 'N/A';
                $voyageInfo = "{$depart} → {$arrivee} le {$voyage->voyage_date}";

                // Vérifier qu'on n'a pas déjà envoyé ce rappel
                $dejaEnvoye = \App\Models\Notifications\Notification::where('res_id', $reservation->res_id)
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

        $this->info("Rappels envoyés : {$totalEnvoyes}");
        return Command::SUCCESS;
    }
}
