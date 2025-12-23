<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ThrottleDisponibilite
{
    public function handle(Request $request, Closure $next, $maxAttempts = 60, $decayMinutes = 1) {

        $key = 'disponibilite:' . ($request->user()?->id ?: $request->ip());

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return response()->json([
                'statut' => false,
                'message' => 'Trop de requêtes. Veuillez patienter.',
                'retry_after' => RateLimiter::availableIn($key)
            ], 429);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        // Ajouter des heaters d'information
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $maxAttempts - RateLimiter::attempts($key));

        return $response;
    }
}