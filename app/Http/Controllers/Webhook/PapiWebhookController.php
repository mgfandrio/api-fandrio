<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Commissions\Collecte;
use App\Models\Reservation\Reservation;
use App\Services\Commission\CommissionService;
use App\Services\Paiement\PapiService;
use App\Services\Paiement\ReservationPaiementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks PAPI (publics, serveur-à-serveur) — confirmation de paiement.
 * Sécurisés par vérification de signature (pas d'auth applicative).
 */
class PapiWebhookController extends Controller
{
    public function __construct(
        private PapiService $papi,
        private CommissionService $commissions,
        private ReservationPaiementService $reservations
    ) {
    }

    /**
     * Réception d'une notification de paiement de collecte.
     *
     * TODO(PAPI): adapter les noms de champs du payload (reference/status/transaction_id)
     * aux vrais noms renvoyés par PAPI une fois la doc confirmée.
     */
    public function commission(Request $request): JsonResponse
    {
        if (!$this->papi->verifierSignatureWebhook($request)) {
            Log::warning('PAPI webhook : signature invalide', ['ip' => $request->ip()]);
            return response()->json(['statut' => false, 'message' => 'Signature invalide'], 401);
        }

        $reference = (string) $request->input('reference');                        // ex : "COLLECTE-42"
        $statut    = strtolower((string) $request->input('status'));               // ex : "success"
        $txId      = $request->input('transaction_id') ?? $request->input('id');

        // Ne traiter que les paiements réussis
        if (!in_array($statut, ['success', 'succeeded', 'paid', 'completed'], true)) {
            return response()->json(['statut' => true, 'message' => 'Ignoré (statut non payé).']);
        }

        // Extraire le coll_id depuis la référence "COLLECTE-{id}"
        if (!preg_match('/COLLECTE-(\d+)/', $reference, $m)) {
            return response()->json(['statut' => false, 'message' => 'Référence inconnue.'], 422);
        }

        $collecte = Collecte::find((int) $m[1]);
        if (!$collecte) {
            return response()->json(['statut' => false, 'message' => 'Collecte introuvable.'], 404);
        }

        // Idempotent : marque la collecte réglée + réactive la compagnie si à jour
        $this->commissions->marquerCollecteReglee($collecte, Collecte::MODE_PAPI, $txId);

        return response()->json(['statut' => true, 'message' => 'Collecte réglée.']);
    }

    /**
     * Réception d'une notification de paiement de BILLET (flux client → compagnie).
     * La signature est vérifiée avec le secret webhook de la compagnie destinataire.
     *
     * TODO(PAPI): adapter les noms de champs du payload (reference/status/transaction_id).
     */
    public function reservation(Request $request): JsonResponse
    {
        $reference = (string) $request->input('reference');            // ex : "RES-42"
        $statut    = strtolower((string) $request->input('status'));   // ex : "success"
        $txId      = $request->input('transaction_id') ?? $request->input('id');

        // Retrouver la réservation (référence publique) pour connaître la compagnie
        if (!preg_match('/RES-(\d+)/', $reference, $m)) {
            return response()->json(['statut' => false, 'message' => 'Référence inconnue.'], 422);
        }

        $reservation = Reservation::with('voyage.trajet.compagnie')->find((int) $m[1]);
        if (!$reservation) {
            return response()->json(['statut' => false, 'message' => 'Réservation introuvable.'], 404);
        }

        // Vérifier la signature avec le secret PAPI de la compagnie destinataire
        $secret = $reservation->voyage?->trajet?->compagnie?->comp_papi_webhook_secret;
        if (!$this->papi->verifierSignatureAvecSecret($request, $secret)) {
            Log::warning('PAPI webhook billet : signature invalide', ['ip' => $request->ip()]);
            return response()->json(['statut' => false, 'message' => 'Signature invalide'], 401);
        }

        // Ne traiter que les paiements réussis
        if (!in_array($statut, ['success', 'succeeded', 'paid', 'completed'], true)) {
            return response()->json(['statut' => true, 'message' => 'Ignoré (statut non payé).']);
        }

        // Idempotent : confirme la réservation (statut payé + sièges) + notifie la compagnie
        $this->reservations->confirmerPaiement($reservation, $txId);

        return response()->json(['statut' => true, 'message' => 'Réservation confirmée.']);
    }
}
