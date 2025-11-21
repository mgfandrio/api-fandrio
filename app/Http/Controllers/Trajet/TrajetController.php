<?php

namespace App\Http\Controllers\Trajet;

use App\Http\Controllers\Controller;
use App\Services\Trajet\TrajetService;
use App\DTOs\TrajetDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TrajetController extends Controller 
{
    public function __construct(private TrajetService $trajetService) {}

    /**
     * Récupère la liste des trajets de la compagnie
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filtres = $request->only(['statut', 'pro_depart', 'pro_arrivee', 'pro_arrivee','per_page','sort_field','sort_direction']);
            $resultat = $this->trajetService->listerTrajets($filtres);

            return response()->json([
                'statut' => true,
                'data' => $resultat
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère les statistiques des trajets
     */
    public function statistiques(): JsonResponse 
    {
        try {
            $statistiques = $this->trajetService->getStatistiques();

            return response()->json([
                'statut' => true,
                'data' => $statistiques
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Crée un nouveau trajet
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'traj_nom' => 'required|string|max:200',
                'pro_depart' => 'required|integer|exists:provinces,pro_id',
                'pro_arrivee' => 'required|integer|exists:provinces,pro_id|different:pro_depart',
                'traj_tarif' => 'required|numeric|min:0',
                'traj_km' => 'nullable|integer|min:1',
                'traj_duree' => 'nullable|string|max:50'
            ]);

            $trajetDTO = TrajetDTO::fromRequest($request->all());
            $trajet = $this->trajetService->creerTrajet($trajetDTO);
      
            return response()->json([
                'statut' => true,
                'message' => 'Trajet créé avec succès',
                'data' => $trajet
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données invalides',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    /**
     * Récupère un trajet spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $trajet = $this->trajetService->getTrajet($id);

            return response()->json([
                'statut' => true,
                'data' => $trajet
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Trajet non trouvé'
            ], 404);
        }
    }


    /**
     * Met à jour un trajet
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'traj_nom' => 'required|string|max:200',
                'pro_depart' => 'required|integer|exists:provinces,pro_id',
                'pro_arrivee' => 'required|integer|exists:provinces,pro_id|different:pro_depart',
                'traj_tarif' => 'required|numeric|min:0',
                'traj_km' => 'nullable|integer|min:1',
                'traj_duree' => 'nullable|string|max:50'
            ]);

            $trajetDTO = TrajetDTO::fromRequest($request->all());
            $trajet = $this->trajetService->mettreAJourTrajet($id, $trajetDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Trajet mis à jour avec succès',
                'data' => $trajet
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données invalides',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    /**
     * Activé/désactivé un trajet
     */
    public function changerStatut(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'statut'    => 'equired|integer|in:1,2' // 1: actif, 2: inactif
            ]);

            $trajet = $request->trajetService->changerStatutTrajet($id, $request->statut);

            $message = $request->statut == 1 ? 'Trajet activé avec succès' : 'Trajet désactivé avec succès';

            return response()->json([
                'statut' => true,
                'message' => $message,
                'data' => $trajet
            ]); 

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données invalides',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
