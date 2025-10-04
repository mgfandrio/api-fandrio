<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifierRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $utilisateur = auth()->user();

        if (!$utilisateur) {
            return response()->json([
                'statut' => false,
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }

        if (!in_array($utilisateur->util_role, $roles)) {
            return response()->json([
                'statut' => false,
                'message' => 'Accès non autorisé pour votre rôle'
            ], 403);
        }

        return $next($request);
    }
}