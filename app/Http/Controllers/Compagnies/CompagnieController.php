<?php

namespace App\Http\Controllers\Compagnies;

use App\Http\Controllers\Controller;
use App\Services\Compagnies\CompagnieService;
use App\DTOs\CompagnieDTO;
use App\DTOs\AdminCompagnieDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CompagnieController extends Controller
{
    public function __construct(private CompagnieService $compagnieService) {}

    /**
     * Récupère la liste des compagnies
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filtres = $request->only(['statut', 'recherche', 'per_page']);
            $resultat = $this->compagnieService->listerCompagnies($filtres);

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
     * Récupère les statistiques des compagnies
     */
    public function statistiques(): JsonResponse
    {
        try {
            $statistiques = $this->compagnieService->getStatistiques();

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
     * Crée une nouvelle compagnie
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                // Données compagnie
                'comp_nom' => 'required|string|max:200',
                'comp_nif' => 'required|string|max:50',
                'comp_stat' => 'required|string|max:50',
                'comp_description' => 'required|string',
                'comp_phone' => 'required|string|max:20',
                'comp_email' => 'required|email|max:100',
                'comp_adresse' => 'required|string',
                'provinces_desservies' => 'sometimes|array',
                'provinces_desservies.*' => 'integer|exists:provinces,pro_id',
                'modes_paiement' => 'sometimes|array',
                'modes_paiement.*' => 'integer|exists:types_paiement,type_paie_id',
                
                // Données admin compagnie
                'admin_nom' => 'required|string|max:100',
                'admin_prenom' => 'required|string|max:100',
                'admin_email' => 'required|email|max:150',
                'admin_telephone' => 'required|string|max:20',
                'admin_mot_de_passe' => 'required|string|min:6'
            ]);

            $compagnieDTO = CompagnieDTO::fromRequest($request->all());
            $adminDTO = AdminCompagnieDTO::fromRequest($request->all());

            $resultat = $this->compagnieService->creerCompagnie($compagnieDTO, $adminDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Compagnie créée avec succès',
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
     * Récupère une compagnie spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $compagnie = $this->compagnieService->getCompagnie($id);

            return response()->json([
                'statut' => true,
                'data' => $compagnie
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Compagnie non trouvée'
            ], 404);
        }
    }

    /**
     * Met à jour une compagnie
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'comp_nom' => 'required|string|max:200',
                'comp_nif' => 'required|string|max:50',
                'comp_stat' => 'required|string|max:50',
                'comp_description' => 'required|string',
                'comp_phone' => 'required|string|max:20',
                'comp_email' => 'required|email|max:100',
                'comp_adresse' => 'required|string',
                'provinces_desservies' => 'sometimes|array',
                'provinces_desservies.*' => 'integer|exists:fandrio_app.provinces,pro_id',
                'modes_paiement' => 'sometimes|array',
                'modes_paiement.*' => 'integer|exists:fandrio_app.types_paiement,type_paie_id'
            ]);

            $compagnieDTO = CompagnieDTO::fromRequest($request->all());
            $resultat = $this->compagnieService->mettreAJourCompagnie($id, $compagnieDTO);

            return response()->json([
                'statut' => true,
                'message' => 'Compagnie mise à jour avec succès',
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

    /**
     * Active/désactive une compagnie
     */
    public function changerStatut(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'statut' => 'required|integer|in:1,2' // 1: actif, 2: inactif
            ]);

            $resultat = $this->compagnieService->changerStatutCompagnie($id, $request->statut);

            $message = $request->statut == 1 ? 'Compagnie activée avec succès' : 'Compagnie désactivée avec succès';

            return response()->json([
                'statut' => true,
                'message' => $message,
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

    /**
     * Supprime une compagnie
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->compagnieService->supprimerCompagnie($id);

            return response()->json([
                'statut' => true,
                'message' => 'Compagnie supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la suppression'
            ], 400);
        }
    }
}