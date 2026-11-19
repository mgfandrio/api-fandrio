<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // onOneServer : avec plusieurs serveurs API (planificateur lancé sur plusieurs
        // machines par erreur), chaque tâche ne tourne qu'une fois par échéance
        // (verrou dans le cache Redis partagé). Sans effet sur un seul serveur.
        // withoutOverlapping : si une exécution dépasse la minute (forte charge),
        // la suivante est sautée au lieu de tourner en parallèle.

        // Nettoyer les locks toutes les minutes
        $schedule->command('sieges:nettoyer-locks')->everyMinute()->withoutOverlapping()->onOneServer();

        // Libérer les réservations dont le délai de paiement est dépassé
        // (anciennement exécuté à chaque GET plan de sièges / dashboard client)
        $schedule->command('reservations:liberer-expirees')->everyMinute()->withoutOverlapping()->onOneServer();

        // Publication automatique des voyages planifiés (non publiés) dont la date de publication est atteinte
        $schedule->command('voyages:publier')->everyMinute()->withoutOverlapping()->onOneServer();

        // Gestion automatique des statuts de voyages (chaque minute : remplace
        // aussi autoCompleterVoyages(), anciennement exécuté à chaque GET admin)
        $schedule->command('voyages:gestion-statuts')->everyMinute()->withoutOverlapping()->onOneServer();

        // Suivi des voitures en route : départ automatique si l'embarquement n'est pas
        // clôturé 1 h après l'heure de départ, alertes de retard
        $schedule->command('voyages:suivi')->everyMinute()->withoutOverlapping()->onOneServer();

        // Rappels de voyage : J-2, J-1, J-0 (dédup par jour) + avertissement clients 8h avant
        // (toutes les heures pour couvrir les voyages partant à n'importe quelle heure)
        $schedule->command('voyages:rappels')->hourly()->onOneServer();

        // Génération automatique des collectes de commission (chaque jour à 06:00)
        $schedule->command('commissions:generer-collectes')->dailyAt('06:00')->onOneServer();

        // Suspension des compagnies à commission impayée (> délai de grâce) + réactivation (07:00)
        $schedule->command('commissions:suspendre-impayees')->dailyAt('07:00')->onOneServer();

        // Versements aux chauffeurs : figer les voyages passés et rappeler les non réglés (08:00)
        $schedule->command('versements:rappels')->dailyAt('08:00')->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
