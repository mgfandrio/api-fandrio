<?php

namespace App\Services\Commission;

use App\Models\Commissions\Collecte;
use App\Models\Compagnies\Compagnie;

/**
 * Logique partagée de règlement des collectes de commission et de
 * réactivation des compagnies suspendues (source unique de vérité,
 * utilisée par la confirmation manuelle super-admin ET le webhook PAPI).
 */
class CommissionService
{
    /** Délai de grâce (jours) après l'échéance avant/pour la suspension. */
    public const GRACE_DAYS = 3;

    /**
     * Réactive la compagnie si elle n'a plus aucune collecte en retard
     * (en attente + échéance dépassée depuis plus que le délai de grâce).
     */
    public function reactiverCompagnieSiAJour(int $compId): void
    {
        $limite = now()->subDays(self::GRACE_DAYS)->toDateString();

        $encoreEnRetard = Collecte::where('comp_id', $compId)
            ->where('coll_statut', Collecte::EN_ATTENTE)
            ->whereDate('coll_date_prevue', '<', $limite)
            ->exists();

        if (!$encoreEnRetard) {
            Compagnie::where('comp_id', $compId)
                ->where('comp_suspendu_commission', true)
                ->update(['comp_suspendu_commission' => false]);
        }
    }

    /**
     * Marque une collecte comme réglée (payée) et réactive la compagnie si à jour.
     * Idempotent : ne fait rien si la collecte est déjà confirmée.
     */
    public function marquerCollecteReglee(Collecte $collecte, string $mode, ?string $papiTransactionId = null): void
    {
        if ((int) $collecte->coll_statut === Collecte::CONFIRMEE) {
            return;
        }

        $collecte->update([
            'coll_statut'              => Collecte::CONFIRMEE,
            'coll_date_confirmation'   => now(),
            'coll_paye_le'             => now(),
            'coll_mode'                => $mode,
            'coll_papi_transaction_id' => $papiTransactionId ?? $collecte->coll_papi_transaction_id,
        ]);

        $this->reactiverCompagnieSiAJour($collecte->comp_id);
    }
}
