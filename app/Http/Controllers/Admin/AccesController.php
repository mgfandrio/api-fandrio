<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Admin système : accès des comptes.
 * - désigner l'admin principal d'une compagnie (transfert du rôle) ;
 * - donner un mot de passe provisoire à un admin compagnie ou à un client
 *   qui a perdu le sien (à changer à sa prochaine connexion).
 */
class AccesController extends Controller
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** PATCH /api/admin/compagnies/{id}/admin-principal { util_id } */
    public function designerPrincipal(Request $request, int $id): JsonResponse
    {
        $request->validate(['util_id' => 'required|integer']);
        $nouveau = Utilisateur::where('util_id', (int) $request->util_id)->where('comp_id', $id)->where('util_role', 2)->first();
        if (!$nouveau) {
            return response()->json(['statut' => false, 'message' => 'Cet administrateur n’appartient pas à cette compagnie.'], 404);
        }
        if ((int) $nouveau->util_statut !== 1) {
            return response()->json(['statut' => false, 'message' => 'Réactivez d’abord ce compte avant d’en faire l’admin principal.'], 422);
        }

        DB::transaction(function () use ($id, $nouveau) {
            // Un seul admin principal par compagnie (index unique) : retirer l'ancien d'abord
            Utilisateur::where('comp_id', $id)->where('util_admin_principal', true)->update(['util_admin_principal' => false]);
            $nouveau->update(['util_admin_principal' => true]);
        });

        return response()->json(['statut' => true, 'message' => "{$nouveau->util_prenom} {$nouveau->util_nom} est maintenant l’admin principal de la compagnie."]);
    }

    /** POST /api/admin/utilisateurs/{id}/mot-de-passe-provisoire */
    public function motDePasseProvisoire(int $id): JsonResponse
    {
        $utilisateur = Utilisateur::where('util_id', $id)->whereIn('util_role', [1, 2])->where('util_statut', '<>', 3)->first();
        if (!$utilisateur) {
            return response()->json(['statut' => false, 'message' => 'Compte introuvable.'], 404);
        }

        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $motDePasse = substr($c, 0, 4) . '-' . substr($c, 4);
        $utilisateur->update(['util_password' => Hash::make($motDePasse), 'util_mdp_provisoire' => true]);

        return response()->json([
            'statut' => true,
            'message' => "Nouveau mot de passe provisoire pour {$utilisateur->util_prenom} {$utilisateur->util_nom}.",
            // Affiché une seule fois : jamais stocké en clair
            'data' => ['mot_de_passe_provisoire' => $motDePasse],
        ]);
    }
}
