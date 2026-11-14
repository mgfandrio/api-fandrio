<?php

namespace App\Console\Commands;

use App\Services\Voyage\VoyageService;
use Illuminate\Console\Command;

/**
 * Publie les voyages planifiés (non publiés) dont la date de publication programmée est
 * atteinte : ils deviennent visibles et réservables par les clients.
 * Planifiée chaque minute (app/Console/Kernel.php).
 */
class PublierVoyagesCommand extends Command
{
    protected $signature = 'voyages:publier';

    protected $description = 'Publie les voyages planifiés dont la date de publication est atteinte';

    public function handle(VoyageService $voyageService): int
    {
        $publies = $voyageService->publierVoyagesProgrammes();

        if ($publies > 0) {
            $this->info("→ {$publies} voyage(s) publié(s)");
        }

        return self::SUCCESS;
    }
}
