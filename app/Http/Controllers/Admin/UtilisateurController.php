<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\UtilisateurService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UtilisateurController extends Controller
{
    public function __construct(private UtilisateurService $utilisateurService) {}

    /**
     * Récupère la liste des utilisateurs clients
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filtres = $request->only(['statut', 'recherche', 'per_page', 'sort_field', 'sort_direction']);
            $resultat = $this->utilisateurService->listerUtilisateurs($filtres);

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
     * Récupère les statistiques des utilisateurs
     */
    public function statistiques(): JsonResponse
    {
        try {
            $statistiques = $this->utilisateurService->getStatistiques();

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
     * Récupère un utilisateur spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $utilisateur = $this->utilisateurService->getUtilisateur($id);

            return response()->json([
                'statut' => true,
                'data' => $utilisateur
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Utilisateur non trouvé'
            ], 404);
        }
    }

    /**
     * Active un utilisateur
     */
    public function activer(int $id): JsonResponse
    {
        try {
            $utilisateur = $this->utilisateurService->activerUtilisateur($id);

            return response()->json([
                'statut' => true,
                'message' => 'Utilisateur activé avec succès',
                'data' => $utilisateur
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Désactive un utilisateur
     */
    public function desactiver(int $id): JsonResponse
    {
        try {
            $utilisateur = $this->utilisateurService->desactiverUtilisateur($id);

            return response()->json([
                'statut' => true,
                'message' => 'Utilisateur désactivé avec succès',
                'data' => $utilisateur
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Supprime un utilisateur
     */
    public function supprimer(int $id): JsonResponse
    {
        try {
            $this->utilisateurService->supprimerUtilisateur($id);

            return response()->json([
                'statut' => true,
                'message' => 'Utilisateur supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Réactive un utilisateur supprimé
     */
    public function reactiver(int $id): JsonResponse
    {
        try {
            $utilisateur = $this->utilisateurService->reactiverUtilisateur($id);

            return response()->json([
                'statut' => true,
                'message' => 'Utilisateur réactivé avec succès',
                'data' => $utilisateur
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Change le statut d'un utilisateur
     */
    public function changerStatut(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'statut' => 'required|integer|in:1,2,3' // 1: actif, 2: inactif, 3: supprimé
            ]);

            $utilisateur = $this->utilisateurService->changerStatutUtilisateur($id, $request->statut);

            $messages = [
                1 => 'Utilisateur activé avec succès',
                2 => 'Utilisateur désactivé avec succès',
                3 => 'Utilisateur supprimé avec succès'
            ];

            return response()->json([
                'statut' => true,
                'message' => $messages[$request->statut],
                'data' => $utilisateur
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