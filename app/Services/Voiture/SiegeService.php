<?php 

namespace App\Services\Voiture;

use App\Models\Voitures\SiegeReserve;
use App\Models\Voyages\Voyage;
use App\Models\Voitures\PlanSiege;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class SiegeService
{
    private const CACHE_DURATION = 30;
    private const LOCK_DURATION = 300; // 5min pour la sélection
    private const CACHE_KEY_PREFIX = 'sieges_voyage_';
    private const REDIS_CHANNEL_PREFIX = 'sieges_update_';


    /**
     * Récupère le plan de sièges d'un voyage
     */
    public function getPlanSieges(int $voyageId): array 
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $voyageId;

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $voyage = Voyage::with('voiture.planSieges')->findOrFail($voyageId);
        $planSiege = $voyage->voiture->planSiege;

        if (!$planSiege) {
            throw new \Exception('Aucun plan de sièges configuré pour ce véhicule');
        }

        $sieges = $this->genererPlanSiegesAvecStatuts($voyageId, $planSiege);

        Cache::put($cacheKey, $sieges, self::CACHE_DURATION);

        return $sieges;
     }


     /** 
      * Sélectionne temporairement un siège
      */
     public function selectionnerSiege(int $voyageId, string $siegeNumero, int $utilisateurId): array 
     {
        return DB::transaction(function () use ($voyageId, $siegeNumero, $utilisateurId) {

            // Verrouillage
            $siege = SiegeReserve::where('voyage_id', $voyageId)
                ->where('siege_numero', $siegeNumero)
                ->lockForUpdate()
                ->first();
            
            if (!$siege) {
                // Créer l'entrée si elle n'existe pas
                $siege = SiegeReserve::create([
                    'voyage_id' => $voyageId,
                    'siege_numero' => $siegeNumero,
                    'siege_statut' => 2, // Disponible par défaut
                    'utilisateur_id' => null
                ]);
            }

            // Vérifier la disponibilité
            if (!$siege->estDisponible()) {
                throw new \Exception('Ce siège n\'est pas disponible');
            }

            // Verrouiller temporairement
            if (!$siege->verrouillerTemporairement($utilisateurId, self::LOCK_DUARATION)) {
                throw new \Exception('Impossible de verrouiller ce siège');
            }

            // Invalider le cache
            $this->invaliderCache($voyageId);

            // Publier la mise à jour via Redis
            $this->publierMiseAJour($voyageId, $siegeNumero, 'selectionne', $utilisateurId);

            return [
                'success' => true,
                'siege' => $siegeNumero,
                'utilisateur_id' => $utilisateurId,
                'expire_lock' => $siege->expire_lock->format('Y-m-d H:i:s'),
                'message' => 'Siège temporairement réservé'
            ];
        });
     }



     /**
      *  Libère un siège sélectionné
      */
     public function libererSiege(int $voyageId, string $siegeNumero, int $utilisateurId): array
     {
        $siege = SiegeReserve::where('voyage_id', $voyageId)
            ->where('siege_numero', $siegeNumero)
            ->firstOrFail();
        
        // Vérifier que l'utilisateur est bien celui qui a verrouillé
        if ($siege->utilisateur_id !== $utilisateurId && $siege->siege_statut == 3) {
            throw new \Exception('Vous ne pouvez pas libérer ce siège');
        }

        $siege->liberer();

        // Invalider le cache
        $this->invaliderCache($voyageId);

        // Publier la mise à jour  via Redis
        $this->publierMiseAJour($voyageId, $siegeNumero, 'libere', $utilisateurId);

        return [
            'success' => true,
            'siege' => $siegeNumero,
            'message' => 'Siège libéré'
        ];
     }


     /**
      * Vérifie la disponibilité de plusieurs sièges
      */
    public function verifierDisponibiliteSieges(int $voyageId, array $siegesNumeros): array 
    {
        $sieges = SiegeReserve::where('voyage_id', $voyageId)
            ->whereIn('siege_numero', $siegesNumeros)
            ->get()
            ->keyBy('siege_numero');
        
        $resultats = [];

        foreach ($siegesNumeros as $numero) {

            $siege = $sieges->get($numero);

            $resultats[$numero] = [
                'disponible' => !$siege || $siege->estDisponible(),
                'statut' => $siege ? $siege->siege_statut : 2,
                'utilisateur_id' => $siege ? $siege->utilisateur_id : null,
                'expire_lock' => $siege && $siege->expire_lock ? 
                    $siege->expire_lock->format('Y-m-d H:i:s') : null
            ];
        }
        return $resultats;
    }


    /**
     * Récupère les sièges déjà réservés pour un voyage
     */
    public function getSiegesReserves(int $voyageId): array 
    {
        return SiegeReserve::where('voyage_id', $voyageId)
            ->where('siege_statut', 1) // Réservé définitivement
            ->pluck('siege_numero')
            ->toArray();
    }


    /***
     * Récupère les sièges temporairement sélectionnés
     */
    public function getSiegesTemporaires(int $voyageId): array 
    {
        return SiegeReserve::where('voyage_id', $voyageId)
            ->where('siege_statut', 3)
            ->where('expire_lock', '>', now())
            ->get()
            ->map(function ($siege) {
                return [
                    'siege' => $siege->siege_numero,
                    'utilisateur_id' => $siege->utilisateur_id,
                    'expire_lock' => $siege->expire_lock->format('Y-m-d H:i:s')
                ];
            })
            ->toArray();
    }


    /**
     * Nettoie les locks expirés
     */
    public function nettoyerLocksExpires(): int 
    {
        $expired = SiegeReserve::where('siege_statut', 3)
            ->where('expire_lock', '<', now())
            ->get();

        foreach ($expired as $siege) {
            $siege->liberer();
            $this->publierMiseAJour(
                $siege->voyage_id, 
                $siege->siege_numero, 
                'expire', 
                $siege->utilisateur_id
            );
        }

        return $expired->count();
    }



    /**
     * Génère le plan de sièges avec les statuts actuels
     */
    private function genererPlanSiegesAvecStatuts(int $voyageId, PlanSiege $planSiege): array 
    {
        $siegesConfig = $planSiege->getSiegesParses();
        $siegesReserves = $this->getSiegesReserves($voyageId);
        $siegesTemporaires = $this->getSiegesTemporaires($voyageId);

        // Créer un map des sièges temporaires
        $tempMap = [];
        foreach ($siegesTemporaires as $temp) {
            $tempMap[$temp['siege']] = $temp;
        }

        // Appliquer les statuts
        foreach ($siegesConfig as $siege) {
            $numero = $siege['code'];

            if (in_array($numero, $siegesReserves)) {
                $siege['statut'] = 'reserve';
                $siege['couleur'] = '#dc3545'; // Rouge
                $siege['message'] = 'Réservé';
            } elseif (isset($tempMap[$numero])) {
                $siege['statut'] = 'selectionne';
                $siege['couleur'] = '#ffc107'; // Orange
                $siege['utilisateur_id'] = $tempMap[$numero]['utilisateur_id'];
                $siege['expire_lock'] = $tempMap[$numero]['expire_lock'];
                $siege['message'] = 'Sélectionné temporairement';
            } else {
                $siege['statut'] = 'disponible';
                $siege['couleur'] = '#28a745'; // Vert
                $siege['message'] = 'Disponible';
            }

            $siege['selectable'] = $siege['statut'] === 'disponible';
        }

        return [
           'voyage_id' => $voyageId,
            'voiture' => [
                'id' => $planSiege->voit_id,
                'matricule' => $planSiege->voiture->voit_matricule ?? '',
                'marque' => $planSiege->voiture->voit_marque ?? '',
                'modele' => $planSiege->voiture->voit_modele ?? ''
            ],
            'plan' => $siegesConfig,
            'total_sieges' => count($siegesConfig),
            'sieges_disponibles' => count(array_filter($siegesConfig, fn($s) => $s['statut'] === 'disponible')),
            'sieges_reserves' => count($siegesReserves),
            'sieges_temporaires' => count($siegesTemporaires),
            'timestamp' => time() 
        ];
    }


    /**
     * Publie une mise à jour via Redis pour les WebSockets
     */
    private function publierMiseAJour(int $voyageId, string $siegeNumero, string $action, ?int $utilisateurId = null): void 
    {
        $channel = self::REDIS_CHANNEL_PREFIX . $voyageId;

        $message = [
            'action' => $action, 
            'voyage_id' => $voyageId,
            'utilisateur_id' => $utilisateurId,
            'timestamp' => time(),
            'data'  => $this->getStatutSiege($voyageId, $siegeNumero)
        ];

        Redis::publish($channel, json_encode($message));
    }


    /**
     * Récupère le statut actuel d'un siège
     */
    private function getStatutSiege(int $voyageId, string $siegeNumero): array 
    {
        $siege = SiegeReserve::where('voyage_id', $voyageId)
            ->where('siege_numero', $siegeNumero)
            ->first();

        return [
            'siege' => $siegeNumero,
            'statut' => $siege ? $siege->siege_statut : 2,
            'disponible' => !$siege || $siege->estDisponible(),
            'utilisateur_id' => $siege ? $siege->utilisateur_id : null,
            'expire_lock' => $siege && $siege->expire_lock ? 
                $siege->expire_lock->format('Y-m-d H:i:s') : null
        ];
    }


    /**
     * Invalide le cache d'un voyage
     */
    private function invaliderCache(int $voyageId): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX . $voyageId);
    }
}