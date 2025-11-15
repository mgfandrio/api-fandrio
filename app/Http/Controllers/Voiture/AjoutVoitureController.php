<?php

namespace App\Http\Controllers\Voiture;

use App\DTOs\VoitureDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Voiture\VoitureService;

class AjoutVoitureController extends Controller
{
    # Ajout d' une nouvelle voiture
    public function ajouterVoiture(Request $request, VoitureService $voitureService): JsonResponse
    {
        try {

            $validationDesDonnees = $request->validate(VoitureDTO::validationDonnees());

            // Ajout d' une voiture via service
            $voitureDTO = VoitureDTO::creationObjet($validationDesDonnees);
            $voiture = $voitureService->ajouterVoiture($voitureDTO);

            // Succes de l' ajout voiture
            return response()->json([
                'statut'  => true,
                'message' => 'La voiture ' . $voiture->voit_matricule . ' - ' . $voiture->voit_marque . ' a bien ete ajoute avec succes',
                'data'    => $voiture
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Ajout d\' une nouvelle voiture echouee: ' . $e->getMessage()
            ], 500);
        }
    }
}
