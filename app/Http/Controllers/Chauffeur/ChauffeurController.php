<?php

namespace App\Http\Controllers\Chauffeur;

use App\Services\Chauffeur\ChauffeurService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\DTOs\ChauffeurDTO;

class ChauffeurController extends Controller
{
    //  Ajout d' un nouveau chauffeur
    public function ajouterChauffeur(Request $request, ChauffeurService $chauffeurService): JsonResponse
    {
        try {

            $validationDesDonnees = $request->validate(ChauffeurDTO::validationDonnees());

            // Ajout d' un chauffeur a partir via service
            $chauffeurDTO = ChauffeurDTO::creationObjet($validationDesDonnees);
            $chauffeur = $chauffeurService->ajouterChauffeur($chauffeurDTO, $request->file('chauff_photo'));

            // Succes de l' ajout chauffeur
            return response()->json([
                'status'  => true,
                'message' => 'Le chauffeur a bien ete ajoute avec succes',
                'data'    => $chauffeur
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
