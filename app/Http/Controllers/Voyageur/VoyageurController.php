<?php

namespace App\Http\Controllers\Voyageur;

use App\DTOs\VoyageurDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Voyageur\VoyageurService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class VoyageurController extends Controller
{
    protected VoyageurService $voyageurService;

    public function __construct(VoyageurService $voyageurService)
    {
        $this->voyageurService = $voyageurService;
    }


    /**
     * Récupérer la liste des voyageurs de l'utilisateur connecté
     * GET /voyageurs
     */
    public function index(): JsonResponse
    {
        try {
            $utilisateur = Auth::user();
            $voyageurs = $this->voyageurService->recupererVoyageursParUtilisateur($utilisateur->util_id);

            return response()->json([
                'statut'  => true,
                'message' => 'Liste des voyageurs récupérée avec succès',
                'data'    => $voyageurs,
                'total'   => $voyageurs->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de la récupération des voyageurs: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Récupérer les détails d'un voyageur spécifique
     * GET /voyageurs/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $utilisateur = Auth::user();
            $voyageur = $this->voyageurService->trouverVoyageurParUtilisateur($id, $utilisateur->util_id);

            if (!$voyageur) {
                return response()->json([
                    'statut'  => false,
                    'message' => 'Voyageur non trouvé ou non autorisé'
                ], 404);
            }

            return response()->json([
                'statut'  => true,
                'message' => 'Détails du voyageur récupérés avec succès',
                'data'    => $voyageur
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de la récupération du voyageur: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Ajouter un nouveau voyageur
     * POST /voyageurs
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $utilisateur = Auth::user();
            
            // Préparation des données avec l'ID de l'utilisateur connecté
            $donnees = $request->all();
            $donnees['util_id'] = $utilisateur->util_id;

            $validationDesDonnees = $request->validate(VoyageurDTO::validationDonnees());
            $validationDesDonnees['util_id'] = $utilisateur->util_id;

            // Création du DTO et ajout du voyageur
            $voyageurDTO = VoyageurDTO::creationObjet($validationDesDonnees);
            $voyageur = $this->voyageurService->ajouterVoyageur($voyageurDTO);

            return response()->json([
                'statut'  => true,
                'message' => 'Le voyageur ' . $voyageur->voya_nom . ' ' . $voyageur->voya_prenom . ' a été ajouté avec succès',
                'data'    => $voyageur
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur de validation',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de l\'ajout du voyageur: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Ajouter plusieurs voyageurs en une seule requête
     * POST /voyageurs/multiple
     */
    public function storeMultiple(Request $request): JsonResponse
    {
        try {
            $utilisateur = Auth::user();

            // Validation des données multiples
            $validationDesDonnees = $request->validate(VoyageurDTO::validationDonneesMultiple());

            // Vérification des CIN uniques dans la requête
            $cins = collect($validationDesDonnees['voyageurs'])
                ->pluck('voya_cin')
                ->filter()
                ->values();

            if ($cins->count() !== $cins->unique()->count()) {
                return response()->json([
                    'statut'  => false,
                    'message' => 'Certains CIN sont en double dans la requête'
                ], 422);
            }

            // Vérification des CIN existants en base
            foreach ($cins as $cin) {
                if ($this->voyageurService->verifierCinExiste($cin)) {
                    return response()->json([
                        'statut'  => false,
                        'message' => 'Le CIN "' . $cin . '" existe déjà dans la base de données'
                    ], 422);
                }
            }

            // Ajout des voyageurs
            $voyageursCreer = $this->voyageurService->ajouterPlusieursVoyageurs(
                $validationDesDonnees['voyageurs'],
                $utilisateur->util_id
            );

            return response()->json([
                'statut'  => true,
                'message' => $voyageursCreer->count() . ' voyageur(s) ajouté(s) avec succès',
                'data'    => $voyageursCreer,
                'total'   => $voyageursCreer->count()
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur de validation',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de l\'ajout des voyageurs: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Modifier un voyageur existant
     * PUT /voyageurs/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $utilisateur = Auth::user();

            // Vérifier que le voyageur appartient à l'utilisateur
            $voyageurExistant = $this->voyageurService->trouverVoyageurParUtilisateur($id, $utilisateur->util_id);

            if (!$voyageurExistant) {
                return response()->json([
                    'statut'  => false,
                    'message' => 'Voyageur non trouvé ou non autorisé'
                ], 404);
            }

            // Validation des données
            $validationDesDonnees = $request->validate(VoyageurDTO::validationDonneesAmodifier($id));

            // Création du DTO et modification
            $voyageurDTO = VoyageurDTO::creationObjetAmodifier($validationDesDonnees, $utilisateur->util_id);
            $voyageur = $this->voyageurService->modifierVoyageur($id, $voyageurDTO);

            return response()->json([
                'statut'  => true,
                'message' => 'Le voyageur ' . $voyageur->voya_nom . ' ' . $voyageur->voya_prenom . ' a été modifié avec succès',
                'data'    => $voyageur
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur de validation',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de la modification du voyageur: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Supprimer un voyageur
     * DELETE /voyageurs/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $utilisateur = Auth::user();

            // Vérifier que le voyageur appartient à l'utilisateur
            $voyageurExistant = $this->voyageurService->trouverVoyageurParUtilisateur($id, $utilisateur->util_id);

            if (!$voyageurExistant) {
                return response()->json([
                    'statut'  => false,
                    'message' => 'Voyageur non trouvé ou non autorisé'
                ], 404);
            }

            $resultat = $this->voyageurService->supprimerVoyageur($id);

            if (!$resultat['succes']) {
                return response()->json([
                    'statut'  => false,
                    'message' => $resultat['message']
                ], 400);
            }

            return response()->json([
                'statut'  => true,
                'message' => $resultat['message']
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de la suppression du voyageur: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Supprimer plusieurs voyageurs en une seule requête
     * DELETE /voyageurs/multiple
     */
    public function destroyMultiple(Request $request): JsonResponse
    {
        try {
            $utilisateur = Auth::user();

            $validationDesDonnees = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer'
            ]);

            // Vérifier que tous les voyageurs appartiennent à l'utilisateur
            $voyageursNonAutorises = [];
            foreach ($validationDesDonnees['ids'] as $idVoyageur) {
                $voyageur = $this->voyageurService->trouverVoyageurParUtilisateur($idVoyageur, $utilisateur->util_id);
                if (!$voyageur) {
                    $voyageursNonAutorises[] = $idVoyageur;
                }
            }

            if (!empty($voyageursNonAutorises)) {
                return response()->json([
                    'statut'  => false,
                    'message' => 'Certains voyageurs ne vous appartiennent pas ou n\'existent pas',
                    'ids_non_autorises' => $voyageursNonAutorises
                ], 403);
            }

            $resultats = $this->voyageurService->supprimerPlusieursVoyageurs($validationDesDonnees['ids']);

            return response()->json([
                'statut'  => true,
                'message' => count($resultats['supprimes']) . ' voyageur(s) supprimé(s)',
                'supprimes' => $resultats['supprimes'],
                'echecs' => $resultats['echecs']
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur de validation',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de la suppression des voyageurs: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Récupérer les voyageurs associés à une réservation
     * GET /voyageurs/reservation/{resId}
     */
    public function parReservation(int $resId): JsonResponse
    {
        try {
            $voyageurs = $this->voyageurService->recupererVoyageursParReservation($resId);

            return response()->json([
                'statut'  => true,
                'message' => 'Voyageurs de la réservation récupérés avec succès',
                'data'    => $voyageurs,
                'total'   => $voyageurs->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de la récupération des voyageurs: ' . $e->getMessage()
            ], 500);
        }
    }
}
