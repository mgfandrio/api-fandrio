<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authentification\InscriptionRequest;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class InscriptionController extends Controller
{
    /**
     * Inscrire un nouvel utilisateur (uniquement pour les utilisateurs standard)
     */
    public function inscription(InscriptionRequest $request): JsonResponse
    {
        try {
            // Créer le nouvel utilisateur avec le rôle 1 (utilisateur standard)
            $utilisateur = Utilisateur::create([
                'util_nom' => $request->util_nom,
                'util_prenom' => $request->util_prenom,
                'util_email' => $request->util_email,
                'util_phone' => $request->util_phone,
                'util_password' => Hash::make($request->util_password),
                'util_anniv' => $request->util_anniv,
                'util_role' => 1, // Utilisateur standard
                'util_statut' => 1, // Actif par défaut
            ]);

            // Générer le token JWT
            $token = JWTAuth::fromUser($utilisateur);

            return response()->json([
                'success' => true,
                'message' => 'Inscription réussie.',
                'data' => [
                    'token' => $token,
                    'type' => 'bearer',
                    'expires_in' => auth()->factory()->getTTL() * 60,
                    'utilisateur' => [
                        'id' => $utilisateur->util_id,
                        'nom' => $utilisateur->util_nom,
                        'prenom' => $utilisateur->util_prenom,
                        'email' => $utilisateur->util_email,
                        'telephone' => $utilisateur->util_phone,
                        'role' => $utilisateur->util_role
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'inscription : ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'inscription.'
            ], 500);
        }
    }
}