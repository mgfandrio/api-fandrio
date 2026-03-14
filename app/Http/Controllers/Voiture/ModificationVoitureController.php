<?php

namespace App\Http\Controllers\Voiture;

use App\DTOs\VoitureDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Voiture\VoitureService;

class ModificationVoitureController extends Controller
{
    /**
     * Modification d'une voiture existante
     */
    public function modifierVoiture(Request $request, int $id, VoitureService $voitureService): JsonResponse
    {
        try {
            // Validation des données
            $validationDesDonnees = $request->validate(VoitureDTO::validationDonneesAmodifier($id));

            // Modification via service
            $voitureDTO = VoitureDTO::creationObjetAmodifier($validationDesDonnees);
            $voiture = $voitureService->modifierVoiture($id, $voitureDTO);

            return response()->json([
                'statut'  => true,
                'message' => 'La voiture ' . $voiture->voit_matricule . ' a bien été modifiée avec succès',
                'data'    => $voiture
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Voiture non trouvée'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la modification de la voiture: ' . $e->getMessage()
            ], 500);
        }
    }
}
