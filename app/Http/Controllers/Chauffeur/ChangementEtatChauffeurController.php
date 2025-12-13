<?php

namespace App\Http\Controllers\Chauffeur;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Chauffeur\ChauffeurService;

class ChangementEtatChauffeurController extends Controller
{
    private function verificationValeurStatut($statutRequete)
    {
        // Accepter uniquement 0 ou 1
        $valeursAcceptes = ['1', '2', 1, 2];
        if (!in_array($statutRequete, $valeursAcceptes, true) && !in_array((string) $statutRequete, ['0', '1'], true)) {
            return response()->json([
                'statut' => false,
                'message' => 'Valeur du champ "statut" invalide. Attendu 0 ou 1.'
            ], 422);
        }
    }

    public function changerEtatChauffeur(Request $request, int $idChauffeur, ChauffeurService $chauffeurService): JsonResponse
    {
        $chauffeur = $chauffeurService->trouverUnChauffeur($idChauffeur);

        if (!$chauffeur) {
            return response()->json([
                'statut' => false,
                'message' => 'Chauffeur non trouvé'
            ], 400);
        }

        // Récupère la valeur envoyée
        $statutRequete = $request->input('chauff_statut');
        $this->verificationValeurStatut($statutRequete);

        $statutActuel = (int) $chauffeur->chauff_statut;

        if ($statutActuel === 1) {
            $nouveauStatut = 2;
            $messageRetour = 'Chauffeur activé avec succès';
        } elseif ($statutActuel === 2) {
            $nouveauStatut = 1;
            $messageRetour = 'Chauffeur désactivé avec succès';
        } elseif ($statutActuel === 3) {
            return response()->json([
                'statut' => false,
                'message' => 'Impossible de changer l\'état d\'un chauffeur supprimé'
            ], 400);
        } else {
            return response()->json([
                'statut' => false,
                'message' => 'Statut actuel du chauffeur invalide en base'
            ], 500);
        }

        try {
            $chauffeur->chauff_statut = $nouveauStatut;
            $chauffeur->update();
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors du changement d\'état du chauffeur: ' . $e->getMessage()
            ], 500);
        }

        return response()->json([
            'statut' => true,
            'message' => $messageRetour
        ], 200);
    }
}
