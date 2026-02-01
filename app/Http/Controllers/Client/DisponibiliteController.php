<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Client\DisponibiliteService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DisponibiliteController extends Contreller
{
    public function __construct(private DisponibiliteService $disponibiliteService){}

    /**
     * Récupère les disponibilités d'un voyage
     */
    public function show(int $voyageId): JsonResponse
    {
        try {
            $disponibilite = $this->disponibiliteService->getDisponibilite($voyageId);

            return response()->json([
                'statut' => true,
                'message' => 'Disponibilités récupérées avec succès',
                'data' => $disponibilite
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 404);
        } 
    }


    /**
     * Récupère les disponibilités pour plusieurs voyages
     */
    public function showMultiple(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'voyage_ids' => 'required|array|min:1|max:20',
                'voyage_ids.*' => 'integer|exists:fandrio_app.voyages,voyage_id'
            ]);

            $disponibilites = $this->disponibiliteService->getDisponibilitesMultiple(
                $request->voyage_ids
            );

            return response()->json([
                'statut' => true,
                'message' => 'Disponibilités récupérées avec succès',
                'data' => $disponibilites
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données de invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    /**
     * Vérifie la disponibilité pour un nombre spécifique de places
     */
    public function verifierNombrePlaces(int $voyageId, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'nb_places' => 'required|integer|min:1|max:20'
            ]);

            $verification = $this->disponibiliteService->verifierDisponibilite(
                $voyageId,
                $request->nb_places
            );

            return response()->json([
                'statut' => true,
                'message' => 'Vérification effectuée',
                'data' => $verification
            ]);

        } catch( \Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    /**
     * Rafraîchit les disponibilités (force le rafraîchissement du cache)
     */
    public function rafraichir(int $voyageId): JsonResponse
    {
        try {
            $disponibilite = $this->disponibiliteService->rafraichirDisponibilite($voyageId);

            return response()->json([
                'statut' => true,
                'message' => 'Disponibilités rafraîchies avec succès',
                'data' => $disponibilite
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }


    /**
     * Récupère l'historique des modifications de places
     */
    public function historique(int $voyageId): JsonResponse
    {
         try {
            $historique = $this->disponibiliteService->getHistoriquePlaces($voyageId);

            return response()->json([
                'statut' => true,
                'message' => 'Historique récupéré avec succès',
                'data' => $historique
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    /**
     * Endpoint de santé pour vérifier la fraîcheur des données
     */
    public function sante(int $voyageId): JsonResponse
    {
        try {
            $cacheKey = DisponibiliteService::CACHE_KEY_PREFIX . $voyageId;
            $ageCache = $donneesCache ? time() - $donneesCache['timestamp'] : null;

            $voyage = \App\Models\Voyages\Voyage::find($voyageId);

            $sante = [
                'voyage_id' => $voyageId,
                'voyage_existe' => !is_null($voyage),
                'voyage_statut' => $voyage->voyage_statut ?? null,
                'cache_present' => !is_null($donneesCache),
                'age_cache_secondes' => $ageCache,
                'fraicheur' => $ageCache < 10 ? 'excellente' : ($ageCache < 30 ? 'bonne' : 'a_rafraichir'),
                'places_reelles' => $voyage ? [
                    'disponibles' => $voyage->places_disponibles,
                    'reservees' => $voyage->places_reservees,
                    'libres' => $voyage->places_disponibles - $voyage->places_reservees
                ] : null,
                'timestamp_serveur' => time(),
                'recommendation' => $ageCache > 30 ? 'Rafraîchir les données' : 'Données à jour'
            ];

            return response()->json([
                'statut' => true,
                'data' => $sante
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}