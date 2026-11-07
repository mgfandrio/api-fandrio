<?php

namespace App\Services\Reservation;

use App\Events\SiegeUpdated;
use App\Models\Reservation\Reservation;
use App\Models\Voitures\SiegeReserve;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Libère les réservations en attente (statut 1) dont le délai de paiement
 * est dépassé : réservation annulée (statut 4) + sièges remis disponibles.
 *
 * Exécutée par la commande planifiée `reservations:liberer-expirees` (chaque
 * minute) et, ciblée sur un voyage, juste avant une nouvelle réservation.
 * Auparavant appelée à chaque GET (plan de sièges, dashboard client) :
 * scan global + écritures à chaque lecture => contention sous charge.
 */
class ReservationExpireeService
{
    /**
     * @param int|null $voyageId Limiter à un voyage (null = tous)
     * @return int Nombre de réservations libérées
     */
    public function liberer(?int $voyageId = null): int
    {
        $ids = Reservation::where('res_statut', 1)
            ->where('date_limite_paiement', '<', now())
            ->when($voyageId !== null, fn($q) => $q->where('voyage_id', $voyageId))
            ->pluck('res_id');

        $count = 0;

        foreach ($ids as $resId) {
            if ($this->libererReservation($resId)) {
                $count++;
            }
        }

        if ($count > 0) {
            Log::info("Nettoyage: $count réservations expirées libérées.");
        }

        return $count;
    }

    /**
     * Libère une réservation expirée. Idempotent et sûr en concurrence :
     * la mise à jour conditionnelle (res_statut = 1) garantit qu'un seul
     * processus (scheduler ou requête) traite la réservation.
     */
    private function libererReservation(int $resId): bool
    {
        return DB::transaction(function () use ($resId) {
            $reservation = Reservation::where('res_id', $resId)
                ->where('res_statut', 1)
                ->where('date_limite_paiement', '<', now())
                ->lockForUpdate()
                ->first();

            if (!$reservation) {
                return false; // déjà traitée ou payée entre-temps
            }

            // Marquer la réservation comme annulée
            // (le trigger SQL décrémente places_reservees du voyage)
            $reservation->update(['res_statut' => 4]);

            // Libérer les sièges associés
            $sieges = SiegeReserve::where('res_id', $resId)->get();
            foreach ($sieges as $siege) {
                $siegeNumero = $siege->siege_numero;
                $siege->update([
                    'siege_statut' => 2,
                    'utilisateur_id' => null,
                    'res_id' => null,
                    'expire_lock' => null
                ]);

                broadcast(new SiegeUpdated(
                    $reservation->voyage_id,
                    $siegeNumero,
                    'expire',
                    null,
                    ['siege' => $siegeNumero, 'statut' => 2, 'disponible' => true, 'utilisateur_id' => null, 'expire_lock' => null]
                ))->toOthers();
            }

            return true;
        });
    }
}
