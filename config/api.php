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
];