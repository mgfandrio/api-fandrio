<?php 

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Voiture\SiegeService;

class NettoyerLocksSieges extends Command
{
    protected $signature = 'sieges:nettoyer-locks';
    protected $description = 'Nettoie les locks de sièges expirés';

    public function handle(SiegeService $siegeService)
    {
        $count = $siegeService->nettoyerLocksExpires();
        
        $this->info("{$count} locks expirés nettoyés.");
        
        if ($count > 0) {
            $this->info('Notifications envoyées aux clients.');
        }
    }
}