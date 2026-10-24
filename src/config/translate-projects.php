<?php

return [
    // API Key del proyecto en translateprojects.dev
    'api_key' => env('TRANSLATE_PROJECTS_API_KEY'),

    // Permite apuntar a otro backend (local, staging)
    'api_url' => env('TRANSLATE_PROJECTS_API_URL', 'https://api.translateprojects.dev'),

    // Idioma de los textos del código; debe ser el idioma por defecto del proyecto en Translate Projects
    'default_locale' => env('TRANSLATE_PROJECTS_DEFAULT_LOCALE', env('APP_LOCALE', 'en')),

    // Idiomas a traducir, separados por coma en el .env (p. ej. "en,pt-BR,fr")
    'target_languages' => array_filter(array_map('trim', explode(',', env('TRANSLATE_PROJECTS_TARGET_LANGUAGES', 'es,fr,de,it')))),

    // Carpetas (relativas a la raíz del proyecto) donde translate:scan busca textos
    'scan_paths' => ['app', 'resources', 'routes'],
];
