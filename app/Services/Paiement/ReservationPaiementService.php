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

    /** Résultats de confirmerPaiement() */
    public const CONFIRMEE = 'confirmee';
    public const DEJA_TRAITEE = 'deja_traitee';
    public const REMBOURSEMENT = 'remboursement';

    /**
     * Traite un paiement réussi (appelé par le webhook billet). Idempotent.
     *
     * - Réservation en attente (statut 1) avec ses sièges : confirmée
     *   (statut payé + sièges définitivement réservés + notification compagnie).
     * - Réservation déjà libérée (expirée / abandonnée) : le paiement est arrivé
     *   trop tard, ses sièges ont pu être réattribués. La réservation reste
     *   annulée et un remboursement "en attente" est créé (flux Remboursements
     *   de l'admin compagnie) ; client et compagnie sont notifiés.
     *
     * La réservation est verrouillée (FOR UPDATE) : pas de course avec la
     * libération des réservations expirées (ReservationExpireeService).
     *
     * @return string self::CONFIRMEE | self::DEJA_TRAITEE | self::REMBOURSEMENT
     */
    public function confirmerPaiement(Reservation $reservation, ?string $papiTransactionId): string
    {
        $siegesDiffusion = [];

        $resultat = DB::transaction(function () use ($reservation, $papiTransactionId, &$siegesDiffusion) {
            $reservation = Reservation::where('res_id', $reservation->res_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $reservation->res_statut === 2) {
                return self::DEJA_TRAITEE; // déjà confirmée (webhook rejoué)
            }

            $siegesLies = SiegeReserve::where('res_id', $reservation->res_id)->count();
            $toujoursValide = (int) $reservation->res_statut === 1
                && $siegesLies >= (int) $reservation->nb_voyageurs;

            if (!$toujoursValide) {
                if ((int) $reservation->res_remb_statut > 0) {
                    return self::DEJA_TRAITEE; // remboursement déjà créé (webhook rejoué)
                }

                $reservation->update([
                    'res_statut'      => 4, // reste / devient annulée (sièges libérés)
                    'numero_paiement' => $papiTransactionId,
                    'montant_avance'  => $reservation->montant_total, // paiement reçu
                    'montant_restant' => 0,
                    'res_remb_statut' => 1, // remboursement en attente
                    'res_remb_montant' => $reservation->montant_total,
                    'date_limite_paiement' => null,
                ]);

                // Cas limite : sièges encore rattachés (réservation incomplète) → libérés
                SiegeReserve::where('res_id', $reservation->res_id)
                    ->where('siege_statut', 3)
                    ->update([
                        'siege_statut' => 2,
                        'utilisateur_id' => null,
                        'res_id' => null,
                        'expire_lock' => null,
                    ]);

                return self::REMBOURSEMENT;
            }

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

            return self::CONFIRMEE;
        });

        if ($resultat === self::DEJA_TRAITEE) {
            return $resultat;
        }

        if ($resultat === self::REMBOURSEMENT) {
            Log::warning('PAPI : paiement reçu après expiration, remboursement créé', [
                'res_id' => $reservation->res_id,
                'transaction_id' => $papiTransactionId,
            ]);
            $this->notifierPaiementTardif($reservation);
            return $resultat;
        }

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

        return $resultat;
    }

    /**
     * Notifie le client (remboursement à venir) et la compagnie (remboursement
     * à effectuer) d'un paiement arrivé après expiration. Best-effort.
     */
    private function notifierPaiementTardif(Reservation $reservation): void
    {
        try {
            $reservation = $reservation->fresh(['voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee', 'utilisateur']);
            $voyage = $reservation->voyage;
            $client = $reservation->utilisateur;
            $clientNom = trim(($client->util_prenom ?? '') . ' ' . ($client->util_nom ?? ''));
            $depart = $voyage->trajet->provinceDepart->pro_nom ?? 'N/A';
            $arrivee = $voyage->trajet->provinceArrivee->pro_nom ?? 'N/A';
            $voyageInfo = "{$depart} → {$arrivee} ({$voyage->voyage_date->format('d/m/Y')})";
            $montant = (float) $reservation->res_remb_montant;

            NotificationService::notifierClientPaiementTardif(
                (int) $reservation->util_id,
                (int) $reservation->res_id,
                $montant,
                $voyageInfo
            );
            NotificationService::notifierAdminPaiementTardif(
                (int) $voyage->trajet->comp_id,
                (int) $reservation->res_id,
                $clientNom,
                $montant,
                $voyageInfo
            );
        } catch (\Throwable $e) {
            Log::warning('Notification paiement tardif échouée : ' . $e->getMessage());
        }
    }
}
