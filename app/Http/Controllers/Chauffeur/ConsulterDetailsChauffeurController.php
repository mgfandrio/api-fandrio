<?php

namespace App\Http\Controllers\Chauffeur;

use App\Http\Controllers\Controller;
use App\Services\Chauffeur\ChauffeurService;

class ConsulterDetailsChauffeurController extends Controller
{
    public function detailChauffeur(int $idChauffeur)
    {
        $chauffeurService = new ChauffeurService();
        $chauffeur = $chauffeurService->trouverUnChauffeur($idChauffeur);

        if (!$chauffeur) {
            return response()->json([
                'statut' => false,
                'message' => 'Chauffeur non trouve'
            ], 400);
        }

        return response()->json([
            'statut' => true,
            'data' => $chauffeur
        ], 200);
    }
}
