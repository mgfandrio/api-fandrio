<?php

namespace App\Http\Controllers\Chauffeur;

use App\DTOs\ChauffeurDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Chauffeur\ChauffeurService;

class ModificationChauffeurController extends Controller
{
    # Modification information d' un chauffeur existant
    public function modifierChauffeur(Request $request, int $idChauffeur, ChauffeurService $chauffeurService): JsonResponse
    {
        $chauffeur = $chauffeurService->trouverUnChauffeur($idChauffeur);

        if (!$chauffeur) {
            return response()->json([
                'statut' => false,
                'message' => 'Chauffeur non trouve'
            ], 400);
        }

        // Message de retour de cet endpoint
        $retourMessage = 'La modification de l\' information chauffeur ';
        $retourMessage .= $chauffeur->chauff_nom . ' ' . $chauffeur->chauff_prenom;


        try {

            $validationDesDonnees = $request->validate(ChauffeurDTO::validationDonneesAmodifier($idChauffeur));

            // Modification d' un chauffeur via un service
            $chauffeurDTO = ChauffeurDTO::creationObjetAmodifier($validationDesDonnees, $chauffeur->chauff_cin);
            $chauffeurModifie = $chauffeurService->modifierChauffeur($idChauffeur, $chauffeurDTO, $request->chauff_photo);

            $retourMessage .= ' a bien ete effectuee avec succes';

            return response()->json([
                'statut' => true,
                'message' => $retourMessage,
                'data' => $chauffeurModifie
            ], 200);
        } catch (\Exception $e) {

            $retourMessage .= ' n\' a pas ete effectuee: ' . $e->getMessage();

            return response()->json([
                'statut' => false,
                'message' => $retourMessage
            ], 500);
        }
    }
}
