<?php

namespace App\Http\Controllers\Voiture;

use App\Models\Voitures\Voitures;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RecuperationListeVoitureController extends Controller
{
    public function listeVoitures(Request $request)
    {
        $compagnieId = $request->user()->comp_id;
        // Avec le chauffeur affecté (affiché dans le choix du véhicule d'un voyage)
        $voitures = Voitures::with('chauffeur:chauff_id,chauff_nom,chauff_prenom,chauff_phone')
            ->where('comp_id', $compagnieId)
            ->get();

        // Liste vide = compagnie sans véhicule (nouvelle compagnie) : ce n'est pas une erreur
        return response()->json([
            'statut' => true,
            'message' => $voitures->isEmpty() ? 'Aucune voiture enregistree' : 'Liste des voitures recuperee avec succes',
            'data' => $voitures
        ], 200);
    }
}
