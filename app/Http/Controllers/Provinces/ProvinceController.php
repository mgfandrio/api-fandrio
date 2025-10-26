<?php

namespace App\Http\Controllers\Provinces;

use App\Http\Controllers\Controller;
use App\Services\Province\ProvinceService;
use App\DTOs\ProvinceDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProvinceController extends Controller
{
    public function __construct(private ProvinceService $provinceService) {}

    /**
     * Récupère la liste des provinces
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filtres = $request->only(['pro_nom', 'pro_orientation', 'per_page', 'paginer']);
            $resultat = $this->provinceService->listerProvinces($filtres);

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
     * Récupère les statistiques des provinces
     */
    public function statistiques(): JsonResponse
    {
        try {
            $statistiques = $this->provinceService->getStatistiques();

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
     * Récupère les orientations disponibles
     */
    public function orientations(): JsonResponse
    {
        try {
            $orientations = $this->provinceService->getOrientations();

            return response()->json([
                'statut' => true,
                'data' => $orientations
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crée une nouvelle province
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'pro_nom' => 'required|string|max:100',
                'pro_orientation' => 'required|string|max:20'
            ]);

            $provinceDTO = ProvinceDTO::fromRequest($request->all());
            $province = $this->provinceService->creerProvince($provinceDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Province créée avec succès',
                'data' => $province
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
     * Crée plusieurs provinces en lot
     */
    public function storeMultiple(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'provinces' => 'required|array|min:1',
                'provinces.*.pro_nom' => 'required|string|max:100',
                'provinces.*.pro_orientation' => 'required|string|max:20'
            ]);

            $resultat = $this->provinceService->creerProvincesEnLot($request->provinces);

            return response()->json([
                'statut' => true,
                'message' => 'Création en lot terminée',
                'data' => $resultat
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
     * Récupère une province spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $province = $this->provinceService->getProvince($id);

            return response()->json([
                'statut' => true,
                'data' => $province
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Province non trouvée'
            ], 404);
        }
    }

    /**
     * Met à jour une province
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'pro_nom' => 'required|string|max:100',
                'pro_orientation' => 'required|string|max:20'
            ]);

            $provinceDTO = ProvinceDTO::fromRequest($request->all());
            $province = $this->provinceService->mettreAJourProvince($id, $provinceDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Province mise à jour avec succès',
                'data' => $province
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
     * Supprime une province
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->provinceService->supprimerProvince($id);

            return response()->json([
                'statut' => true,
                'message' => 'Province supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Supprime plusieurs provinces en lot
     */
    public function destroyMultiple(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'province_ids' => 'required|array|min:1',
                'province_ids.*' => 'integer|exists:fandrio_app.provinces,pro_id'
            ]);

            $resultat = $this->provinceService->supprimerProvincesEnLot($request->province_ids);

            return response()->json([
                'statut' => true,
                'message' => 'Suppression en lot terminée',
                'data' => $resultat
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