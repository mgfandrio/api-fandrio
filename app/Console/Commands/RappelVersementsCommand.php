<?php

namespace App\Console\Commands;

use App\Services\Notification\NotificationService;
use App\Services\Versement\VersementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Chaque matin : fige les versements des voyages passés (voiture partie ou
 * non) puis rappelle à chaque compagnie les versements aux chauffeurs non
 * réglés après la date du voyage (une notification par compagnie et par jour).
 */
class RappelVersementsCommand extends Command
{
    protected $signature = 'versements:rappels';
    protected $description = 'Fige les versements des voyages passés et rappelle aux compagnies les versements non réglés.';

    private const NOTIF_RAPPEL_VERSEMENT = 18;

    public function handle(VersementService $service): int
    {
        $service->synchroniserCompagnie();

        $aujourdhui = today()->toDateString();
        $enRetard = DB::table('fandrio_app.versements')
            ->where('vers_statut', 1)
            ->whereNotNull('vers_fige_le')
            ->where('voyage_date', '<', $aujourdhui)
            ->where(fn($q) => $q->whereNull('vers_rappel_le')->orWhere('vers_rappel_le', '<', $aujourdhui))
            ->orderBy('voyage_date')
            ->get()
            ->groupBy('comp_id');

        foreach ($enRetard as $compId => $versements) {
            $total = number_format((float) $versements->sum('part_chauffeur'), 0, ',', ' ');
            $premier = $versements->first();
            $detail = "{$premier->trajet_libelle} du " . date('d/m', strtotime($premier->voyage_date)) . " ({$premier->voit_matricule}" . ($premier->chauffeur_nom ? ", {$premier->chauffeur_nom}" : '') . ')';
            $nb = $versements->count();
            $message = $nb === 1
                ? "Le versement au chauffeur du voyage {$detail} n'est pas encore marqué comme réglé : {$total} Ar."
                : "{$nb} versements aux chauffeurs ne sont pas encore marqués comme réglés ({$total} Ar), dont le voyage {$detail}.";

            NotificationService::notifierAdminsCompagnie((int) $compId, self::NOTIF_RAPPEL_VERSEMENT, 'Versement à régler', $message);
            DB::table('fandrio_app.versements')->whereIn('vers_id', $versements->pluck('vers_id'))->update(['vers_rappel_le' => now()]);
            $this->info("→ Compagnie {$compId} : {$nb} versement(s) rappelé(s)");
        }

        return self::SUCCESS;
    }
}
