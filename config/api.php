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

    /*
    |--------------------------------------------------------------------------
    | Super-administrateur système
    |--------------------------------------------------------------------------
    |
    | Connexion spéciale qui ouvre le compte `email`. Le mot de passe n'est
    | jamais stocké en clair : seulement son empreinte bcrypt, générée par
    |   php -r 'echo password_hash("MOT_DE_PASSE", PASSWORD_BCRYPT, ["cost"=>12]);'
    | Dans le .env, mettre l'empreinte entre apostrophes ('...') : sinon
    | Docker Compose interprète les "$" qu'elle contient.
    | Si l'identifiant ou l'empreinte est vide, cette connexion est désactivée.
    |
    */

    'admin_systeme' => [
        'identifiant'       => env('ADMIN_SYSTEME_IDENTIFIANT'),
        'mot_de_passe_hash' => env('ADMIN_SYSTEME_MDP_HASH'),
        'email'             => env('ADMIN_SYSTEME_EMAIL', 'admin@fandrio.mg'),
    ],
];