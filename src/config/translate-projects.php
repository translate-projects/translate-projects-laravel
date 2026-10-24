<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Key para autenticación
    |--------------------------------------------------------------------------
    |
    | Define la API Key para conectarte al servicio de traducción.
    |
    */
    'api_key' => env('TRANSLATE_PROJECTS_API_KEY', null),

    /*
    |--------------------------------------------------------------------------
    | Idioma por defecto
    |--------------------------------------------------------------------------
    |
    | Este será el idioma base desde el cual se realizarán las traducciones.
    |
    */
    'default_locale' => env('TRANSLATE_PROJECTS_DEFAULT_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Idiomas a traducir automáticamente
    |--------------------------------------------------------------------------
    |
    | Lista de idiomas a los que se traducirá el contenido automáticamente.
    |
    */
    'target_languages' => [
        'es', // Español
        'fr', // Francés
        'de', // Alemán
        'it', // Italiano
    ],
];
