<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifierCleApi
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $cleApi = $request->header('X-API-KEY');

        if (!$cleApi || $cleApi !== config('app.api_key')) {
            return response()->json([
                'statut' => false,
                'message' => 'Clé API invalide'
            ], 401);
        }

        return $next($request);
    }
}