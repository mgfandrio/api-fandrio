<?php

namespace App\Controller\Voyage;

use App\Http\Controllers\Controller;
use App\Services\Voyage\VoyageService;
use App\DTOs\VoyageDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VoyageController extends Controller
{
    public function __construct(private VoyageService $voyageService) {}

    /**
     * Récupère la liste des voyages de la compagnie
     */
    public function index(Request $request): JsonResponse
    {
         try {
            $filtres = $request->only(['date_debut', 'date_fin', 'statut', 'traj_id', 'per_page', 'sort_field', 'sort_direction']);
            $resultat = $this->voyageService->listerVoyages($filtres);

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
     * Récupère les statistiques des voyages
     */
    public function statistiques(): JsonResponse
    {
         try {
            $statistiques = $this->voyageService->getStatistiques();

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
     * Programmé un nouveau voyage
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'voyage_date' => 'required|date|after:today',
                'voyage_heure_depart' => 'required|date_format:H:i',
                'traj_id' => 'required|integer|exists:fandrio_app.trajets,traj_id',
                'voit_id' => 'required|integer|exists:fandrio_app.voitures,voit_id',
                'voyage_type' => 'sometimes|integer|in:1,2',
                'places_disponibles' => 'required|integer|min:1'
            ]);

            $voyageDTO = VoyageDTO::fromRequest($request->all());
            $voyage = $this->voyageService->programmerVoyage($voyageDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Voyage programmé avec succès',
                'data' => $voyage
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
     * Récupère un voyage spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $voyage = $this->voyageService->getVoyage($id);

            return response()->json([
                'statut' => true,
                'data' => $voyage
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Voyage non trouvé'
            ], 404);
        }
    }


    /**
     * Met à jour un voyage
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'voyage_date' => 'required|date|after:today',
                'voyage_heure_depart' => 'required|date_format:H:i',
                'traj_id' => 'required|integer|exists:fandrio_app.trajets,traj_id',
                'voit_id' => 'required|integer|exists:fandrio_app.voitures,voit_id',
                'voyage_type' => 'sometimes|integer|in:1,2',
                'places_disponibles' => 'required|integer|min:1'
            ]);

            $voyageDTO = VoyageDTO::fromRequest($request->all());
            $voyage = $this->voyageService->mettreAJourVoyage($id, $voyageDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Voyage mis à jour avec succès',
                'data' => $voyage
            ], 200);

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
     * Annule un voyage
     */
    public function annuler(int $id): JsonResponse
    {
        try {
            $voyage = $this->voyageService->annulerVoyage($id);

            return response()->json([
                'statut' => true,
                'message' => 'Voyage annulé avec succès',
                'data' => $voyage
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}