<?php

namespace App\Http\Controllers\Chauffeur;

use App\Http\Controllers\Controller;
use App\Models\Chauffeurs\Chauffeurs;

class RecuperationListeChauffeurController extends Controller
{
    public function listeChauffeurs()
    {
        $chauffeurs = Chauffeurs::all();

        return response()->json(
            [
                'statut' => true,
                'message' => 'Liste des chauffeurs recuperee avec succes',
                'data' => $chauffeurs
            ],
            200
        );

        if (!$chauffeurs) {
            return response()->json(
                [
                    'statut' => false,
                    'message' => 'Aucun chauffeur trouve'
                ],
                404
            );
        }
    }
}
