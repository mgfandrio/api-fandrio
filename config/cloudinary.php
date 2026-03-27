<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cloudinary Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration pour le service Cloudinary (hébergement d'images).
    | Les logos des compagnies sont stockés dans le dossier "fandrio/logos".
    |
    */

    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
    'api_key' => env('CLOUDINARY_API_KEY'),
    'api_secret' => env('CLOUDINARY_API_SECRET'),

    // Dossier de stockage sur Cloudinary
    'folder' => 'fandrio/logos',

    // Taille max autorisée (en Mo)
    'max_size_mb' => 5,

    // Transformations par défaut pour les logos
    'logo_transformation' => [
        'width' => 400,
        'height' => 400,
        'crop' => 'fill',
        'quality' => 'auto',
        'fetch_format' => 'auto',
    ],
];
