# Translate Projects – Laravel

Traduce los textos de tu app Laravel con [Translate Projects](https://translateprojects.dev). El paquete extrae los textos de `__()`, `trans()` y `@lang()` a `lang/{idioma}.json`, los sube a `api.translateprojects.dev` y escribe los archivos traducidos `lang/en.json`, `lang/pt_BR.json`, etc., que Laravel usa directamente.

## Requisitos

- PHP >= 8.1
- Laravel 10, 11, 12 o 13

## Instalación

```bash
composer require neiderruiz/translate-projects-laravel
php artisan vendor:publish --tag=translate-config   # opcional
```

## Configuración

En el `.env`:

```env
TRANSLATE_PROJECTS_API_KEY=tu_api_key
APP_LOCALE=es                                   # idioma de los textos del código
TRANSLATE_PROJECTS_TARGET_LANGUAGES=en,pt-BR,fr
```

| Clave (`config/translate-projects.php`) | Variable de entorno | Por defecto |
|---|---|---|
| `api_key` | `TRANSLATE_PROJECTS_API_KEY` | — |
| `api_url` | `TRANSLATE_PROJECTS_API_URL` | `https://api.translateprojects.dev` |
| `default_locale` | `TRANSLATE_PROJECTS_DEFAULT_LOCALE` o `APP_LOCALE` | `en` |
| `target_languages` | `TRANSLATE_PROJECTS_TARGET_LANGUAGES` (separados por coma) | `es,fr,de,it` |
| `scan_paths` | — | `app`, `resources`, `routes` |

`default_locale` debe coincidir con el idioma por defecto del proyecto en Translate Projects. La IA (modelo y API key) se configura en el proyecto, en translateprojects.dev.

## Uso

```bash
php artisan translate:scan        # extrae los textos a lang/es.json
php artisan translate             # traduce a todos los target_languages
php artisan translate pt-BR       # solo un idioma
php artisan translate --force     # vuelve a traducir aunque no haya cambios
```

- **`translate:scan`** busca `__('...')`, `trans('...')`, `trans_choice('...')` y `@lang('...')` con un texto literal. Añade los textos nuevos a `lang/{idioma base}.json` y conserva los que ya estaban. Omite las claves de grupo (`auth.failed`, si existe `lang/es/auth.php`) y las de paquetes (`pkg::archivo.clave`).
- **`translate`** sube los textos solo si cambiaron, espera al backend (WebSocket de progreso) y escribe `lang/{idioma}.json`. Conserva las entradas que ya había en ese archivo (por ejemplo, traducciones hechas a mano). Guarda en `lang/.translate-projects.json` qué versión tiene cada idioma: una segunda corrida sin cambios hace una sola petición y no traduce nada.

## Errores

El comando nunca deja un archivo a medias ni lo borra:

- Falta `TRANSLATE_PROJECTS_API_KEY` → falla sin hacer peticiones.
- Token inválido o error 4xx → muestra el código y el mensaje de la API, y no toca los archivos.
- Error 429/5xx o de red → reintenta 3 veces. Si sigue fallando, deja ese idioma como estaba y sigue con los demás.
- Respuesta parcial (por ejemplo, el proyecto no tiene la IA configurada: `MISSING_API_KEY`) → escribe lo que llegó, avisa de cuántos textos faltan y los reintenta en la próxima corrida.
- Si la IA cambia un `:placeholder`, esa traducción se descarta y Laravel muestra el texto original.

## Limitaciones

- Solo se traducen los archivos JSON (textos usados como clave). Los archivos de grupo `lang/{idioma}/*.php` no se suben.
- No se detectan las claves dinámicas: `__($variable)`.

## Tests

```bash
composer install
composer test
```

Los tests usan Orchestra Testbench con la API simulada (`Http::fake`), así que no hacen peticiones de red.

## Licencia

MIT © Neider Ruiz
