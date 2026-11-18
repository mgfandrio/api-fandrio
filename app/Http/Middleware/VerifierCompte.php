<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Contrôles appliqués à chaque requête authentifiée :
 * - un compte désactivé ou supprimé est coupé aussitôt (sans attendre la fin
 *   de validité de son jeton) ;
 * - un compte au mot de passe provisoire ne peut que changer son mot de passe,
 *   consulter son profil ou se déconnecter.
 */
class VerifierCompte
{
    /** Routes autorisées tant que le mot de passe provisoire n'est pas changé. */
    private const AUTORISEES_MDP_PROVISOIRE = ['api/moi', 'api/deconnexion', 'api/profil/mot-de-passe'];

    public function handle(Request $request, Closure $next)
    {
        $utilisateur = auth()->user();
        if (!$utilisateur) {
            return $next($request);
        }

        if ((int) $utilisateur->util_statut !== 1) {
            return response()->json([
                'statut' => false,
                'code' => 'COMPTE_DESACTIVE',
                'message' => 'Votre compte est désactivé. Veuillez contacter l\'administrateur.',
            ], 401);
        }

        if ($utilisateur->util_mdp_provisoire && !in_array($request->path(), self::AUTORISEES_MDP_PROVISOIRE, true)) {
            return response()->json([
                'statut' => false,
                'code' => 'MDP_PROVISOIRE',
                'message' => 'Choisissez votre mot de passe personnel avant de continuer.',
            ], 403);
        }

        return $next($request);
    }
}
