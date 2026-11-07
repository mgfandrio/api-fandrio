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
        // withoutOverlapping : si une exécution dépasse la minute (forte charge),
        // la suivante est sautée au lieu de tourner en parallèle.

        // Nettoyer les locks toutes les minutes
        $schedule->command('sieges:nettoyer-locks')->everyMinute()->withoutOverlapping();

        // Libérer les réservations dont le délai de paiement est dépassé
        // (anciennement exécuté à chaque GET plan de sièges / dashboard client)
        $schedule->command('reservations:liberer-expirees')->everyMinute()->withoutOverlapping();

        // Gestion automatique des statuts de voyages (chaque minute : remplace
        // aussi autoCompleterVoyages(), anciennement exécuté à chaque GET admin)
        $schedule->command('voyages:gestion-statuts')->everyMinute()->withoutOverlapping();

        // Rappels de voyage : J-2, J-1, J-0 (dédup par jour) + avertissement clients 8h avant
        // (toutes les heures pour couvrir les voyages partant à n'importe quelle heure)
        $schedule->command('voyages:rappels')->hourly();

        // Génération automatique des collectes de commission (chaque jour à 06:00)
        $schedule->command('commissions:generer-collectes')->dailyAt('06:00');

        // Suspension des compagnies à commission impayée (> délai de grâce) + réactivation (07:00)
        $schedule->command('commissions:suspendre-impayees')->dailyAt('07:00');
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
