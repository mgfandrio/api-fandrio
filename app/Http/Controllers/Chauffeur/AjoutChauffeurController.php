<?php

namespace App\Http\Controllers\Chauffeur;

use App\DTOs\ChauffeurDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Chauffeur\ChauffeurService;

class AjoutChauffeurController extends Controller
{
    # Ajout d' un nouveau chauffeur
    public function ajouterChauffeur(Request $request, ChauffeurService $chauffeurService): JsonResponse
    {
        try {

            $validationDesDonnees = $request->validate(ChauffeurDTO::validationDonnees());

            // Ajout d' un chauffeur a partir via service
            $chauffeurDTO = ChauffeurDTO::creationObjet($validationDesDonnees);
            $chauffeur = $chauffeurService->ajouterChauffeur($chauffeurDTO, $request->file('chauff_photo'));

            // Succes de l' ajout chauffeur
            return response()->json([
                'statut'  => true,
                'message' => 'Le chauffeur ' . $chauffeur->chauff_nom . ' ' . $chauffeur->chauff_prenom . ' a bien ete ajoute avec succes',
                'data'    => $chauffeur
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Ajout d\' un nouveau chauffeur echoue: ' . $e->getMessage()
            ], 500);
        }
    }
}
