<?php

namespace App\Http\Controllers\Chauffeur;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Chauffeur\ChauffeurService;

class SuppressionChauffeurController extends Controller
{
    public function supprimerChauffeur(int $idChauffeur, ChauffeurService $chauffeurService): JsonResponse
    {
        $chauffeur = $chauffeurService->trouverUnChauffeur($idChauffeur);

        if (!$chauffeur) {
            return response()->json([
                'statut' => false,
                'message' => 'Chauffeur non trouve'
            ], 400);
        }

        // Message de retour de cet endpoint
        $retourMessage = 'La suppression du chauffeur ';
        $retourMessage .= $chauffeur->chauff_nom . ' ' . $chauffeur->chauff_prenom;

        try {
            // Suppression d' un chauffeur via un service
            $chauffeurService->supprimerChauffeur($idChauffeur);

            $retourMessage .= ' a bien ete effectuee avec succes';

            return response()->json([
                'statut' => true,
                'message' => $retourMessage
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
