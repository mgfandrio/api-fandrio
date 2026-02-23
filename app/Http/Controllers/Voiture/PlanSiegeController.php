<?php

namespace App\Http\Controllers\Voiture;

use App\Http\Controllers\Controller;
use App\Services\Voiture\PlanSiegeService;
use App\DTOs\PlanSiegeDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PlanSiegeController extends Controller
{
    public function __construct(private PlanSiegeService $planSiegeService) {}

    /**
     * Crée un nouveau plan de sièges
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate(PlanSiegeDTO::validationDonnees());

            $planSiegeDTO = PlanSiegeDTO::fromRequest($request->all());
            $plan = $this->planSiegeService->creerPlan($planSiegeDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Plan de sièges créé avec succès',
                'data' => $plan
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
     * Met à jour un plan de sièges
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'plan_nom' => 'sometimes|string|max:100',
                'config_sieges' => 'sometimes|array',
                'config_sieges.rangees' => 'sometimes|array|min:1',
                'config_sieges.rangees.*.lettre' => 'sometimes|string|size:1|regex:/^[A-Z]$/',
                'config_sieges.rangees.*.sieges' => 'sometimes|array|min:1',
                'config_sieges.rangees.*.sieges.*' => 'sometimes|string|in:normal,couloir,fenetre,handicape',
                'plan_statut' => 'sometimes|integer|in:1,2'
            ]);

            $planSiegeDTO = PlanSiegeDTO::fromRequestModification($request->all());
            $plan = $this->planSiegeService->mettreAJourPlan($id, $planSiegeDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Plan de sièges mis à jour avec succès',
                'data' => $plan
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

    /**
     * Récupère un plan de sièges spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $plan = $this->planSiegeService->obtenirPlan($id);

            return response()->json([
                'statut' => true,
                'data' => $plan
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Récupère tous les plans de la compagnie
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filtres = [
                'statut' => $request->query('statut'),
                'voit_id' => $request->query('voit_id'),
                'per_page' => $request->query('per_page', 15)
            ];

            $plans = $this->planSiegeService->obtenirPlansCompagnie($filtres);

            return response()->json([
                'statut' => true,
                'data' => $plans
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Récupère le plan d'une voiture
     */
    public function obtenirParVoiture(int $voitureId): JsonResponse
    {
        try {
            $plan = $this->planSiegeService->obtenirPlanParVoiture($voitureId);

            return response()->json([
                'statut' => true,
                'data' => $plan
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Supprime un plan de sièges
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $resultat = $this->planSiegeService->supprimerPlan($id);

            return response()->json([
                'statut' => true,
                'message' => $resultat['message']
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtient les statistiques d'un plan
     */
    public function statistiques(int $id): JsonResponse
    {
        try {
            $stats = $this->planSiegeService->obtenirStatistiquesPlan($id);

            return response()->json([
                'statut' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }
}
