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

            // La compagnie ne se modifie pas : celle de l'admin connecté
            $request->merge(['comp_id' => $request->user()->comp_id]);
            $validationDesDonnees = $request->validate(ChauffeurDTO::validationDonneesAmodifier($idChauffeur));

            // Modification des seuls champs envoyés (la compagnie ne change jamais)
            $chauffeurModifie = $chauffeurService->modifierChauffeur($idChauffeur, $validationDesDonnees, $request->file('chauff_photo'));

            $retourMessage .= ' a bien ete effectuee avec succes';

            return response()->json([
                'statut' => true,
                'message' => $retourMessage,
                'data' => $chauffeurModifie
            ], 200);
        } catch (\Exception $e) {

            $retourMessage .= ' n\' a pas ete effectuee: ' . $this->messageErreur($e);

            return response()->json([
                'statut' => false,
                'message' => $retourMessage
            ], 500);
        }
    }
}
