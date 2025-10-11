<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Models\Chauffeurs\Chauffeurs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChauffeurController extends Controller
{
    //  Ajout d' un nouveau chauffeur
    public function ajouterChauffeur(Request $request): JsonResponse
    {
        try {

            $validationDesDonnees = $request->validate([
                'chauff_nom'    => 'required|string|max:100',
                'chauff_prenom' => 'required|string|max:100',
                'chauff_age'    => 'required|integer|min:18',
                'chauff_cin'    => 'required|string|unique:chauffeurs,chauff_cin',
                'chauff_permis' => 'required|string|in:A,B,C,D',
                'chauff_phone'  => 'required|string|max:20',
                'chauff_statut' => 'required|integer',
                'chauff_photo'  => 'nullable|image|max:2048',
                'comp_id'       => 'required|integer|exists:compagnies,comp_id'
            ]);


            // Stockage de la photo dans public/chauffeurs
            // ainsi que ce chemin dans la base de donnees
            if ($request->hasFile('chauff_photo')) {
                $validationDesDonnees['chauff_photo'] = $request->file('chauff_photo')
                    ->store('chauffeurs', 'public');
            }

            // Ajout d' un chauffeur dans la base de donnees
            $chauffeur = Chauffeurs::create($validationDesDonnees);

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
