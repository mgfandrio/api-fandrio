<?php

namespace App\Console\Commands;

use App\Services\Reservation\ReservationExpireeService;
use Illuminate\Console\Command;

class LibererReservationsExpireesCommand extends Command
{
    protected $signature = 'reservations:liberer-expirees';
    protected $description = 'Annule les réservations en attente dont le délai de paiement est dépassé et libère leurs sièges';

    public function handle(ReservationExpireeService $service): int
    {
        $count = $service->liberer();

        $this->info("{$count} réservation(s) expirée(s) libérée(s).");

        return Command::SUCCESS;
    }
}
