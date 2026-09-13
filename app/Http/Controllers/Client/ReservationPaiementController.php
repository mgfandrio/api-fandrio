<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use App\Services\Paiement\ReservationPaiementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paiement en ligne d'un billet par le client via PAPI (compte de la compagnie).
 */
class ReservationPaiementController extends Controller
{
    public function __construct(private ReservationPaiementService $service)
    {
    }

    /**
     * Initie le paiement PAPI d'une réservation et renvoie le lien de paiement.
     * POST /api/client/reservation/{id}/payer
     */
    public function payer(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();
            $reservation = Reservation::with('voyage.trajet.compagnie')->findOrFail($id);

            if ((int) $reservation->util_id !== (int) $user->util_id) {
                return response()->json(['statut' => false, 'message' => 'Accès refusé à cette réservation.'], 403);
            }

            $data = $this->service->initierPaiement($reservation);

            return response()->json(['statut' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
