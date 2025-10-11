<?php

namespace App\Http\Controllers\Chauffeur;

use App\Models\Chauffeurs\Chauffeurs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\DTOs\ChauffeurDTO;


class ChauffeurController extends Controller
{
    //  Ajout d' un nouveau chauffeur
    public function ajouterChauffeur(Request $request): JsonResponse
    {
        try {

            $validationDesDonnees = $request->validate(ChauffeurDTO::validationDonnees());

            // Stockage de la photo dans public/chauffeurs
            // ainsi que ce chemin dans la base de donnees
            if ($request->hasFile('chauff_photo')) {
                $validationDesDonnees['chauff_photo'] = $request->file('chauff_photo')
                    ->store('chauffeurs', 'public');
            }

            // Ajout d' un chauffeur dans la base de donnees
            $chauffeurDTO = ChauffeurDTO::creationObjet($validationDesDonnees);
            $chauffeur = Chauffeurs::create($chauffeurDTO->convertionDonneesEnTableau());

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
