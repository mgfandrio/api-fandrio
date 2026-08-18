<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Commissions\Collecte;
use App\Services\Commission\CommissionService;
use App\Services\Paiement\PapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook PAPI (public, serveur-à-serveur) — confirmation de paiement d'une commission.
 * Sécurisé par vérification de signature (pas d'auth applicative).
 */
class PapiWebhookController extends Controller
{
    public function __construct(
        private PapiService $papi,
        private CommissionService $commissions
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
}
