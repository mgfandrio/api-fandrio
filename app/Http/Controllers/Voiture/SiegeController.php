<?php 

namespace App\Http\Controllers\Voiture;

use App\Http\Controllers\Controller;
use App\Services\Voiture\SiegeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Facades\Auth;

class SiegeController extends Controller 
{
    public function __construct(private SiegeService $siegeService) {}

    /***
     * Récupère le plan de siège d'un voyage
     */
    public function getPlanSieges(int $voyageId): JsonResponse
    {
        try {
            $planSieges = $this->siegeService->getPlanSieges($voyageId);

            return response()->json([
                'statut' => true,
                'message' => 'Plan de sièges récupéré avec succès',
                'data' => $planSieges
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la récupération du plan de sièges: ' . $e->getMessage()
            ], 404);
        }
    }


    /**
     * Sélectionne temporairement un siège
     */
    public function selectionnerSiege(Request $request, int $voyageId): JsonResponse
    {
        try {
            $request->validate([
                'siege_numero' => 'required|string|max:10',
                'utilisateur_id' => 'required|integer|exists:utilisateurs,util_id'
            ]);

            // Vérifier que l'utilisateur est authentifié et correspond à l'ID fourni
            $utilisateur = Auth::user();
            if (!$utilisateur || $utilisateur->util_id != $request->utilisateur_id) {
                throw new \Exception('Non autorisé');
            }

            $resultat = $this->siegeService->selectionnerSiege(
                $voyageId,
                $request->siege_numero,
                $request->utilisateur_id
            );

            return response()->json([
                'statut' => true,
                'message' => 'Siège sélectionné avec succès',
                'data' => $resultat
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données de requête invalides: ' . $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la sélection du siège: ' . $e->getMessage()
            ], 400);
        }
    } 


    /**
     * Libère un siège sélectionné
     */
    public function libererSiege(Request $request, int $voyageId): JsonResponse
    {
        try {
            $request->validate([
                'siege_numero' => 'required|string|max:10',
                'utilisateur_id' => 'required|integer|exists:utilisateurs,util_id'
            ]);

            // Vérifie que l'utilisateur est authentifié 
            $utilisateur = Auth::user();
            if (!$utilisateur || $utilisateur->util_id != $request->utilisateur_id) {
                throw new \Exception('Non autorisé');
            }

            $resultat = $this->siegeService->libererSiege(
                $voyageId,
                $request->siege_numero,
                $request->utilisateur_id
            );

            return response()->json([
                'statut' => true,
                'message' => 'Siège libéré avec succès',
                'data' => $resultat
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données de requête invalides',
                'erreurs' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la libération du siège: ' . $e->getMessage()
            ], 400);
        }
    }


    /**
     * Vérifie la disponibilité de plusieurs sièges
     */
    public function verifierSieges(Request $request, int $voyageId): JsonResponse
    {
        try {
            $request->validate([
                'sieges' => 'required|array|min:1|max:20',
                'sieges.*' => 'string|max:10'
            ]);

            $resultats = $this->siegeService->verifierDisponibiliteSieges(
                $voyageId,
                $request->sieges
            );

            return response()->json([
                'statut' => true,
                'message' => 'Disponibilité des sièges vérifiée avec succès',
                'data' => $resultats
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données de requête invalides',
                'erreurs' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la vérification des sièges: ' . $e->getMessage()
            ], 400);
        }
    }


    /** 
     * Récupère les sièges déjà reservés
     */
    public function getSiegesReserves (int $voyageId): JsonResponse
    {
        try {
            $sieges = $this->siegeService->getSiegesReserves($voyageId);

            return response()->json([
                'statut' => true,
                'data' => [
                    'voyage_id' => $voyageId,
                    'sieges_reserves' => $sieges,
                    'nombre_sieges_reserves' => count($sieges)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la récupération des sièges réservés: ' . $e->getMessage()
            ], 400);
        }
    }


    /**
     * Récupère les sièges temporairement sélectionnés
     */
    public function getSiegesTemporaires(int $voyageId): JsonResponse
    {
        try {
            $sieges = $this->siegeService->getSiegesTemporaires($voyageId);

            return response()->json([
                'statut' => true,
                'data' => [
                    'voyage_id' => $voyageId,
                    'sieges_temporaires' => $sieges,
                    'nombre_sieges_temporaires' => count($sieges)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    /**
     * Récupère l'URL de connexion WebSocket
     */
    public function getWebSocketConfig(int $voyageId): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est authentifié
            $utilisateur = Auth::user();
            if (!$utilisateur) {
                throw new \Exception('Non autorisé');
            }

            // Construire l'URL WebSocket Reverb
            $scheme = env('REVERB_SCHEME', 'ws');
            $host = env('REVERB_HOST', 'localhost');
            $port = env('REVERB_PORT', 8080);
            $wsUrl = $scheme . '://' . $host . ':' . $port;

            $config = [
                'ws_url' => $wsUrl,
                'reverb_key' => env('REVERB_APP_KEY'),
                'voyage_id' => $voyageId,
                'utilisateur_id' => $utilisateur->util_id,
                'token' => $this->generateWsToken($voyageId, $utilisateur->util_id),
                'channels' => [
                    'sieges' => 'sieges_update_' . $voyageId,
                    'general' => 'voyage_' . $voyageId
                ],
                'ping_interval' => 30,
                'reconnect_delay' => 5000
            ];

            return response()->json([
                'statut' => true,
                'data' => $config
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la récupération de la configuration WebSocket: ' . $e->getMessage()
            ], 400);
        }
    }


    /**
     * Génère un token pour WebSocket
     */
    public function generateWsToken(int $voyageId, int $utilisateurId): string 
    {
        $payload = [
            'voyage_id' => $voyageId,
            'utilisateur_id' => $utilisateurId,
            'exp' => time() + 3600 // 1h
        ];

        return base64_encode(json_encode($payload));
    }
}