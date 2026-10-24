# Translate Projects – Laravel

Paquete de Laravel para extraer las cadenas de traducción de tu proyecto y traducirlas automáticamente con la API de [Translate Projects](https://translateprojects.dev).

## Requisitos

- PHP >= 8.0
- Laravel (con `Illuminate\Support\Facades\Http`)
- Extensión `gmp` de PHP (la usa `HashHelper`)

## Instalación

```bash
composer require neiderruiz/translate-projects-laravel
```

El service provider se registra solo (auto-discovery). Al arrancar, si no existe `config/translate-projects.php`, el paquete lo copia automáticamente. También puedes publicarlo a mano:

```bash
php artisan vendor:publish --tag=translate-config
```

## Configuración

En tu `.env`:

```env
TRANSLATE_PROJECTS_API_KEY=tu_api_key
TRANSLATE_PROJECTS_DEFAULT_LOCALE=en
```

En `config/translate-projects.php`:

| Clave              | Descripción                                   | Valor por defecto        |
|--------------------|-----------------------------------------------|--------------------------|
| `api_key`          | API Key de Translate Projects                 | `null`                   |
| `default_locale`   | Idioma base de las traducciones               | `en`                     |
| `target_languages` | Idiomas a los que se traduce                  | `['es', 'fr', 'de', 'it']` |

## Uso

### 1. Extraer claves de traducción

```bash
php artisan translate:scan
```

Recorre los archivos `.php` y `.blade.php` del proyecto buscando llamadas `__('...')` y `@lang('...')`, y genera `lang/{app.locale}/translations.php`:

```php
<?php

return [
  'Welcome' => 'Welcome',
  'Log in' => 'Log in',
];
```

### 2. Traducir

```bash
php artisan translate
```

Lee `lang/{default_locale}/translations.php`, convierte cada clave en un hash corto (SHA-256 → base36, 15 caracteres) y envía el contenido a `https://translateprojects.dev/translations/load-sources` por cada idioma de `target_languages`.

## Estado actual / limitaciones

- `php artisan translate` **solo sincroniza** las cadenas con la API; el guardado de `lang/{idioma}/translations.php` está comentado en `TranslateCommand`, así que todavía no escribe archivos traducidos.
- `translate:scan` usa `config('app.locale')` y `translate` usa `translate-projects.default_locale`: ambos deben coincidir.
- El escaneo recorre todo `base_path()`, incluido `vendor/`, y solo detecta llamadas con un único argumento de cadena literal (no `trans()`, `trans_choice()` ni `__('key', [...])`).
- `TranslationScanner` (exportar `lang/` a JSON) y varios métodos de `TranslationApi` (`makeTranslations`, `fetchTranslations`, `getTranslations`, `validateChangesFiles`) aún no se usan desde ningún comando.

## Estructura

```
src/
├── TranslateProjectsLaravelServiceProvider.php  # Registra comandos y config
├── TranslationScanner.php                       # Lee lang/*.php y exporta a JSON
├── Commands/
│   ├── TranslateScanCommand.php                 # translate:scan
│   └── TranslateCommand.php                     # translate
├── Helpers/HashHelper.php                       # Hash corto de claves
├── Services/TranslationApi.php                  # Cliente HTTP de la API
└── config/translate-projects.php                # Configuración por defecto
```

## Licencia

MIT © Neider Ruiz
