<?php

namespace App\Http\Controllers\Auth;

use App\Services\Authentification\AuthentificationService;
use App\Services\Cloudinary\CloudinaryService;
use App\Http\Controllers\Controller;
use App\DTOs\ConnexionDTO;
use App\DTOs\InscriptionDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

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
                    'statut' => $utilisateur->util_statut,
                    'photo' => $utilisateur->util_photo
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }
    }

    /**
     * Met à jour les informations personnelles de l'utilisateur connecté
     */
    public function updateProfil(Request $request): JsonResponse
    {
        try {
            $utilisateur = auth()->user();

            $request->validate([
                'nom' => 'sometimes|string|max:100',
                'prenom' => 'sometimes|string|max:100',
                'email' => 'sometimes|email|max:150|unique:fandrio_app.utilisateurs,util_email,' . $utilisateur->util_id . ',util_id',
                'telephone' => 'sometimes|string|max:20|unique:fandrio_app.utilisateurs,util_phone,' . $utilisateur->util_id . ',util_id',
            ]);

            if ($request->has('nom')) $utilisateur->util_nom = $request->nom;
            if ($request->has('prenom')) $utilisateur->util_prenom = $request->prenom;
            if ($request->has('email')) $utilisateur->util_email = $request->email;
            if ($request->has('telephone')) $utilisateur->util_phone = $request->telephone;

            $utilisateur->save();

            return response()->json([
                'statut' => true,
                'message' => 'Profil mis à jour avec succès',
                'utilisateur' => [
                    'id' => $utilisateur->util_id,
                    'nom' => $utilisateur->util_nom,
                    'prenom' => $utilisateur->util_prenom,
                    'email' => $utilisateur->util_email,
                    'telephone' => $utilisateur->util_phone,
                    'role' => $utilisateur->util_role,
                    'compagnie_id' => $utilisateur->comp_id,
                    'statut' => $utilisateur->util_statut,
                    'photo' => $utilisateur->util_photo
                ]
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
                'message' => 'Erreur lors de la mise à jour du profil'
            ], 500);
        }
    }

    /**
     * Change le mot de passe de l'utilisateur connecté
     */
    public function changerMotDePasse(Request $request): JsonResponse
    {
        try {
            $utilisateur = auth()->user();

            $request->validate([
                'ancien_mot_de_passe' => 'required|string',
                'nouveau_mot_de_passe' => 'required|string|min:6|confirmed',
            ]);

            if (!Hash::check($request->ancien_mot_de_passe, $utilisateur->util_password)) {
                return response()->json([
                    'statut' => false,
                    'message' => 'L\'ancien mot de passe est incorrect'
                ], 422);
            }

            $utilisateur->util_password = Hash::make($request->nouveau_mot_de_passe);
            $utilisateur->save();

            return response()->json([
                'statut' => true,
                'message' => 'Mot de passe modifié avec succès'
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
                'message' => 'Erreur lors du changement de mot de passe'
            ], 500);
        }
    }

    /**
     * Upload ou met à jour la photo de profil de l'utilisateur connecté
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        try {
            $utilisateur = auth()->user();

            $request->validate([
                'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
            ]);

            $cloudinaryService = new CloudinaryService();

            // Supprimer l'ancienne photo si c'est une URL Cloudinary
            if ($utilisateur->util_photo && str_contains($utilisateur->util_photo, 'cloudinary')) {
                $oldPublicId = $this->extractPublicId($utilisateur->util_photo);
                if ($oldPublicId) {
                    $cloudinaryService->deleteLogo($oldPublicId);
                }
            }

            // Upload vers Cloudinary
            $folder = config('cloudinary.folder', 'fandrio/logos');
            $publicId = $folder . '/utilisateur_' . $utilisateur->util_id;

            $result = $cloudinaryService->getCloudinary()->uploadApi()->upload(
                $request->file('photo')->getRealPath(),
                [
                    'public_id'     => $publicId,
                    'overwrite'     => true,
                    'resource_type' => 'image',
                    'transformation' => [
                        'width'   => 400,
                        'height'  => 400,
                        'crop'    => 'fill',
                        'gravity' => 'face',
                        'quality' => 'auto',
                        'fetch_format' => 'auto',
                    ],
                ]
            );

            $utilisateur->util_photo = $result['secure_url'];
            $utilisateur->save();

            return response()->json([
                'statut' => true,
                'message' => 'Photo de profil mise à jour',
                'data' => [
                    'photo_url' => $result['secure_url'],
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Fichier invalide',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de l\'upload de la photo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprime la photo de profil de l'utilisateur connecté
     */
    public function deletePhoto(): JsonResponse
    {
        try {
            $utilisateur = auth()->user();

            if (!$utilisateur->util_photo) {
                return response()->json([
                    'statut' => false,
                    'message' => 'Aucune photo à supprimer'
                ], 400);
            }

            if (str_contains($utilisateur->util_photo, 'cloudinary')) {
                $publicId = $this->extractPublicId($utilisateur->util_photo);
                if ($publicId) {
                    $cloudinaryService = new CloudinaryService();
                    $cloudinaryService->deleteLogo($publicId);
                }
            }

            $utilisateur->util_photo = null;
            $utilisateur->save();

            return response()->json([
                'statut' => true,
                'message' => 'Photo de profil supprimée'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la suppression de la photo'
            ], 500);
        }
    }

    /**
     * Extrait le public_id d'une URL Cloudinary
     */
    private function extractPublicId(string $url): ?string
    {
        if (preg_match('/\/upload\/(?:v\d+\/)?(.+)\.\w+$/', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }
}