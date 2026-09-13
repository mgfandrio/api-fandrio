<?php

namespace App\Services\Paiement;

use App\Events\SiegeUpdated;
use App\Models\Reservation\Reservation;
use App\Models\Voitures\SiegeReserve;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paiement en ligne d'un billet (client → compagnie) via PAPI.
 * La compagnie encaisse sur SON compte PAPI (clé propre, chiffrée en base).
 *
 * ⚠️ SCAFFOLD — prêt à brancher : dépend des détails PAPI (voir PapiService,
 * TODO(PAPI)) et de la clé PAPI renseignée sur la compagnie par le super-admin.
 */
class ReservationPaiementService
{
    public function __construct(private PapiService $papi)
    {
    }

    /**
     * Initie le paiement PAPI d'une réservation avec la clé de SA compagnie.
     * Retourne ['payment_url', 'transaction_id', 'montant'].
     */
    public function initierPaiement(Reservation $reservation): array
    {
        if ((int) $reservation->res_statut !== 1) {
            throw new \RuntimeException('Cette réservation ne peut plus être payée.');
        }
        if ($reservation->date_limite_paiement && now()->greaterThan($reservation->date_limite_paiement)) {
            throw new \RuntimeException('Le délai de paiement est dépassé.');
        }

        $compagnie = $reservation->voyage?->trajet?->compagnie;
        if (!$compagnie || !$compagnie->comp_papi_actif || empty($compagnie->comp_papi_api_key)) {
            throw new \RuntimeException('Le paiement en ligne n\'est pas disponible pour cette compagnie.');
        }

        // La clé est déchiffrée par le cast `encrypted` du modèle Compagnie.
        $lien = $this->papi->creerLienPaiement([
            'montant'          => (float) $reservation->montant_total,
            'reference'        => 'RES-' . $reservation->res_id,
            'description'      => 'Billet FANDRIO ' . ($reservation->res_numero ?? ('#' . $reservation->res_id)),
            'notification_url' => url('/api/papi/webhook/reservation'),
        ], $compagnie->comp_papi_api_key);

        return [
            'payment_url'    => $lien['url'],
            'transaction_id' => $lien['transaction_id'],
            'montant'        => (float) $reservation->montant_total,
        ];
    }

    /**
     * Confirme une réservation payée (appelé par le webhook billet).
     * Idempotent. Reprend la logique de ReservationController::confirm
     * (statut payé + sièges définitivement réservés + notification compagnie).
     */
    public function confirmerPaiement(Reservation $reservation, ?string $papiTransactionId): void
    {
        if ((int) $reservation->res_statut === 2) {
            return; // déjà confirmée → idempotent
        }

        $siegesDiffusion = [];

        DB::transaction(function () use ($reservation, $papiTransactionId, &$siegesDiffusion) {
            $reservation->update([
                'res_statut'           => 2, // Confirmé (payé)
                'numero_paiement'      => $papiTransactionId,
                'date_limite_paiement' => null,
            ]);

            // Sièges définitivement réservés (statut 1) — cf. confirm()
            $sieges = SiegeReserve::where('res_id', $reservation->res_id)->get();
            foreach ($sieges as $siege) {
                $siege->update(['siege_statut' => 1, 'expire_lock' => null]);
                $siegesDiffusion[] = ['numero' => $siege->siege_numero, 'util' => $siege->utilisateur_id];
            }
            // NB : places_reservees déjà incrémenté par le trigger SQL à la création.
        });

        // Diffusion temps réel des sièges : best-effort HORS transaction
        // (une panne de broadcast ne doit jamais annuler la confirmation d'un paiement).
        foreach ($siegesDiffusion as $s) {
            try {
                broadcast(new SiegeUpdated(
                    $reservation->voyage_id,
                    $s['numero'],
                    'reserve',
                    $s['util'],
                    ['siege' => $s['numero'], 'statut' => 1, 'disponible' => false, 'utilisateur_id' => $s['util'], 'expire_lock' => null]
                ))->toOthers();
            } catch (\Throwable $e) {
                Log::warning('Broadcast siège (paiement PAPI) échoué : ' . $e->getMessage());
            }
        }

        // Notifier l'admin compagnie (best-effort)
        try {
            $reservation->load(['voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee', 'utilisateur']);
            $voyage = $reservation->voyage;
            $client = $reservation->utilisateur;
            $clientNom = trim(($client->util_prenom ?? '') . ' ' . ($client->util_nom ?? ''));
            $depart = $voyage->trajet->provinceDepart->pro_nom ?? 'N/A';
            $arrivee = $voyage->trajet->provinceArrivee->pro_nom ?? 'N/A';
            $voyageInfo = "{$depart} → {$arrivee} ({$voyage->voyage_date->format('d/m/Y')})";

            NotificationService::notifierAdminReservation(
                $voyage->trajet->comp_id,
                $reservation->res_id,
                $clientNom,
                $voyageInfo
            );
        } catch (\Throwable $e) {
            Log::warning('Notification admin (paiement PAPI) échouée : ' . $e->getMessage());
        }
    }
}
