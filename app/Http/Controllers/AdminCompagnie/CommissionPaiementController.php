<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Http\Controllers\Controller;
use App\Models\Commissions\Collecte;
use App\Services\Paiement\PapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paiement en ligne d'une collecte de commission par l'admin compagnie (role 2).
 * Fandrio est le marchand encaisseur (clé PAPI Fandrio) ; la compagnie est le payeur.
 */
class CommissionPaiementController extends Controller
{
    public function __construct(private PapiService $papi)
    {
    }

    /**
     * Initie le paiement PAPI d'une collecte et renvoie le lien de paiement.
     * L'app ouvre ce lien ; la confirmation arrive ensuite via le webhook PAPI.
     */
    public function payer(Request $request, int $collecteId): JsonResponse
    {
        try {
            $user = $request->user();
            $collecte = Collecte::findOrFail($collecteId);

            // Garde : la collecte doit appartenir à la compagnie de l'admin connecté
            if ((int) $user->comp_id !== (int) $collecte->comp_id) {
                return response()->json(['statut' => false, 'message' => 'Accès refusé à cette collecte.'], 403);
            }

            if ((int) $collecte->coll_statut === Collecte::CONFIRMEE) {
                return response()->json(['statut' => false, 'message' => 'Cette commission est déjà versée.'], 422);
            }

            if (!$this->papi->estConfigure()) {
                return response()->json(['statut' => false, 'message' => 'Paiement en ligne indisponible (PAPI non configuré).'], 503);
            }

            $service = app(\App\Services\Commission\CommissionService::class);
            $lien = $service->creerLien($collecte);
            $collecte->update(['coll_initie_par' => $user->util_id]);
            $service->journaliser($collecte->comp_id, 'versement_lance',
                'Versement de ' . $service->ariary($collecte->coll_montant_commission) . ' lancé (paiement en ligne).',
                $collecte->coll_id, $user->util_id, \App\Models\Commissions\CollecteJournal::ACTEUR_COMPAGNIE);

            return response()->json([
                'statut' => true,
                'data'   => [
                    'payment_url'    => $lien['url'],
                    'transaction_id' => $lien['transaction_id'],
                    'montant'        => (float) $collecte->coll_montant_commission,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur : ' . $this->messageErreur($e)], 500);
        }
    }
}
