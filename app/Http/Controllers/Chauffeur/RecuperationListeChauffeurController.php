<?php

namespace App\Http\Controllers\Chauffeur;

use App\Http\Controllers\Controller;
use App\Models\Chauffeurs\Chauffeurs;
use Illuminate\Http\Request;

class RecuperationListeChauffeurController extends Controller
{
    public function listeChauffeurs(Request $request)
    {
        $compagnieId = $request->user()->comp_id;
        $chauffeurs = Chauffeurs::where('comp_id', $compagnieId)->get();

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
