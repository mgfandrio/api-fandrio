<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration pour la sécurité de l'API FANDRIO
    |
    */

    'keys' => [
        // Clés API valides (séparées par des virgules)
        'valid' => explode(',', env('API_KEYS', '')),
        
        // Clé de développement
        'dev' => env('API_KEY_DEV', ''),
        
        // Clé de production
        'prod' => env('API_KEY_PROD', ''),
        
        // Expiration en jours (0 = pas d'expiration)
        'expiration_days' => env('API_KEY_EXPIRATION', 30),
    ],

    'security' => [
        // Headers acceptés pour la clé API
        'headers' => [
            'X-API-KEY',
            'X-API-Key',
            'API-Key',
        ],
        
        // Require API key for all routes
        'require_key' => env('API_REQUIRE_KEY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket (Reverb) — configuration exposée aux clients
    |--------------------------------------------------------------------------
    |
    | Lue via config() et non env() : env() renvoie null une fois
    | `php artisan config:cache` exécuté (production).
    |
    | REVERB_PUBLIC_* : adresse joignable par l'app mobile / le backoffice.
    | Sous Docker, REVERB_HOST vaut "reverb" (nom interne du conteneur,
    | utilisé par Laravel pour publier) : il faut alors définir ces variables.
    |
    */

    'websocket' => [
        'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'ws')),
        'host'   => env('REVERB_PUBLIC_HOST', env('REVERB_HOST', 'localhost')),
        'port'   => env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 8080)),
        'key'    => env('REVERB_APP_KEY'),
    ],
];