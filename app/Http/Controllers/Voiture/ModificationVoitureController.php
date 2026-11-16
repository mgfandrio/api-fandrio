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
            // La compagnie ne se modifie pas : celle de l'admin connecté
            $request->merge(['comp_id' => $request->user()->comp_id]);
            $validationDesDonnees = $request->validate(VoitureDTO::validationDonneesAmodifier($id));

            // Modification des seuls champs envoyés (la compagnie ne change jamais)
            $voiture = $voitureService->modifierVoiture($id, $validationDesDonnees);

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
                'message' => 'Erreur lors de la modification de la voiture: ' . $this->messageErreur($e)
            ], 500);
        }
    }
}
