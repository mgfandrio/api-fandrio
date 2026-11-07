<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Compteur par utilisateur connecté, sinon par IP.
            // getAuthIdentifier() (= util_id) : `->id` n'existe pas sur Utilisateur,
            // il valait toujours null et tous les clients d'une même IP (CGNAT
            // mobile, Wi-Fi public) partageaient le même quota.
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(60)->by($userId ? 'util:' . $userId : 'ip:' . $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
