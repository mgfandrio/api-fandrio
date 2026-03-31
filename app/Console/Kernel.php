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
        // Nettoyer les locks toutes les minutes
        $schedule->command('sieges:nettoyer-locks')->everyMinute();

        // Gestion automatique des statuts de voyages (toutes les 5 minutes)
        $schedule->command('voyages:gestion-statuts')->everyFiveMinutes();

        // Rappels de voyage : 2 jours, 1 jour, jour J (chaque jour à 7h)
        $schedule->command('voyages:rappels')->dailyAt('07:00');

        // Génération automatique des collectes de commission (chaque jour à 06:00)
        $schedule->command('commissions:generer-collectes')->dailyAt('06:00');
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
