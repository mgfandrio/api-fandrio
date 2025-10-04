<?php

namespace App\Http\Controllers\Auth;

use App\Services\Authentification\AuthentificationService;
use App\Http\Controllers\Controller;
use App\DTOs\ConnexionDTO;
use App\DTOs\InscriptionDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthentificationController extends Controller
{
    public function __construct(private AuthentificationService $authentificationService) {}

    /**
     * Connexion des utilisateurs (clients, admins compagnie, admin système)
     */
    public function connexion(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'identifiant' => 'required|string',
                'motDePasse' => 'required|string'
            ]);

            $connexionDTO = ConnexionDTO::fromRequest($request->all());
            $resultat = $this->authentificationService->connexion($connexionDTO);

            return response()->json($resultat);

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
            ], 401);
        }
    }

    /**
     * Inscription des nouveaux utilisateurs (clients seulement)
     */
    public function inscription(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'nom' => 'required|string|max:100',
                'prenom' => 'required|string|max:100',
                'email' => 'required|email|max:150',
                'telephone' => 'required|string|max:20',
                'motDePasse' => 'required|string|min:6',
                'dateNaissance' => 'sometimes|date'
            ]);

            $inscriptionDTO = InscriptionDTO::fromRequest($request->all());
            $resultat = $this->authentificationService->inscription($inscriptionDTO);

            return response()->json($resultat);

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
     * Rafraîchissement du token JWT
     */
    public function rafraichir(): JsonResponse
    {
        try {
            $resultat = $this->authentificationService->rafraichirToken();

            return response()->json($resultat);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 401);
        }
    }

    /**
     * Déconnexion de l'utilisateur
     */
    public function deconnexion(): JsonResponse
    {
        try {
            $this->authentificationService->deconnexion();

            return response()->json([
                'statut' => true,
                'message' => 'Déconnexion réussie'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la déconnexion'
            ], 500);
        }
    }

    /**
     * Récupère les informations de l'utilisateur connecté
     */
    public function moi(): JsonResponse
    {
        try {
            $utilisateur = auth()->user();

            return response()->json([
                'statut' => true,
                'utilisateur' => [
                    'id' => $utilisateur->util_id,
                    'nom' => $utilisateur->util_nom,
                    'prenom' => $utilisateur->util_prenom,
                    'email' => $utilisateur->util_email,
                    'telephone' => $utilisateur->util_phone,
                    'role' => $utilisateur->util_role,
                    'compagnie_id' => $utilisateur->comp_id,
                    'statut' => $utilisateur->util_statut
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }
    }
}