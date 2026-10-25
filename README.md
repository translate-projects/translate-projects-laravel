# Translate Projects – Laravel

Traduce automáticamente los textos de tu app Laravel con [Translate Projects](https://translateprojects.dev).

📖 Documentación: https://doc.translateprojects.dev/docs/php/laravel

El paquete hace tres cosas:

1. Extrae los textos de `__()`, `trans()`, `trans_choice()` y `@lang()` a `lang/{idioma base}.json`.
2. Los sube a `https://api.translateprojects.dev` y espera a que la IA del proyecto los traduzca.
3. Escribe `lang/{idioma}.json` por cada idioma destino. Laravel lo usa directamente, sin más configuración.

## Requisitos

- PHP >= 8.1
- Laravel 10, 11, 12 o 13
- Un proyecto en translateprojects.dev con:
  - su **API Key** (el token del proyecto);
  - un **modelo y una API key de IA** configurados en el proyecto (sin ellos no se traduce nada, ver [Errores](#errores-comunes));
  - el **idioma por defecto** igual al idioma de los textos de tu código (por ejemplo, `es`).

## Instalación

```bash
composer require translate-projects/laravel
```

El service provider se registra solo (auto-discovery de Laravel). Si quieres editar la configuración, publícala (opcional):

```bash
php artisan vendor:publish --tag=translate-config
```

Eso crea `config/translate-projects.php`.

## Configuración

Añade al `.env`:

```env
TRANSLATE_PROJECTS_API_KEY=la_api_key_de_tu_proyecto
APP_LOCALE=es
TRANSLATE_PROJECTS_TARGET_LANGUAGES=en,pt-BR,fr
```

| Clave en `config/translate-projects.php` | Variable de entorno | Por defecto | Para qué sirve |
|---|---|---|---|
| `api_key` | `TRANSLATE_PROJECTS_API_KEY` | — (obligatoria) | Token del proyecto en Translate Projects |
| `default_locale` | `TRANSLATE_PROJECTS_DEFAULT_LOCALE`, si no `APP_LOCALE` | `en` | Idioma de los textos del código. Debe ser el idioma por defecto del proyecto |
| `target_languages` | `TRANSLATE_PROJECTS_TARGET_LANGUAGES` (separados por coma) | `es,fr,de,it` | Idiomas a los que se traduce |
| `scan_paths` | — | `app`, `resources`, `routes` | Carpetas donde `translate:scan` busca textos |
| `api_url` | `TRANSLATE_PROJECTS_API_URL` | `https://api.translateprojects.dev` | Solo para apuntar a otro backend (local, staging) |

Los códigos de idioma se pueden escribir como `pt-BR`, `pt_BR` o `pt-br`: el paquete usa `pt-BR` con la API y `pt_BR` en el nombre del archivo, que es la convención de Laravel.

> Si usas `php artisan config:cache`, ejecuta `php artisan config:clear` después de cambiar el `.env`.

## Uso

```bash
php artisan translate:scan       # 1. extrae los textos a lang/es.json
php artisan translate            # 2. traduce a todos los target_languages
```

En el código, escribe los textos en el idioma base y usa el texto como clave:

```blade
<h1>{{ __('Hola mundo') }}</h1>
<p>{{ __('Bienvenido, :name', ['name' => $user->name]) }}</p>
```

Opciones de `translate`:

```bash
php artisan translate pt-BR      # solo un idioma (debe estar en target_languages)
php artisan translate --force    # vuelve a traducir aunque no haya cambios
```

Ejemplo de salida:

```
📂 3 textos en es
📝 Traduciendo a en...
   en: 3 traducidos, 0 sin traducir, 0 rechazados · 2.6s
📝 Traduciendo a pt_BR...
   pt_BR: 3 traducidos, 0 sin traducir, 0 rechazados · 2.2s
📝 Traduciendo a fr...
   fr: 3 traducidos, 0 sin traducir, 0 rechazados · 2.1s
⏱ total 8.6s · 8 peticiones HTTP
🎉 Traducción completada.
```

El comando termina con código 0 si todo fue bien y con 1 si algún idioma falló, así que sirve en CI.

### `translate:scan`

- Busca llamadas con un texto literal: `__('...')`, `trans('...')`, `trans_choice('...')` y `@lang('...')`, también con parámetros (`__('Hola :name', [...])`).
- Añade los textos nuevos a `lang/{idioma base}.json` y conserva los que ya había, incluidos los que ya no aparecen en el código.
- Omite las claves de grupo (`__('auth.failed')` cuando existe `lang/es/auth.php`) y las de paquetes (`__('pkg::archivo.clave')`).

## Archivos que genera

```
lang/
├── es.json                  # textos fuente (translate:scan)
├── en.json                  # traducciones (translate)
├── pt_BR.json
├── fr.json
└── .translate-projects.json # caché: versión traducida de cada idioma
```

Cada `lang/{idioma}.json` tiene la forma `{"texto original": "traducción"}`:

```json
{
    "Bienvenido, :name": "Welcome, :name",
    "Guardar cambios": "Save changes",
    "Hola mundo": "Hello world"
}
```

- Si `lang/{idioma}.json` ya existe, se conservan sus entradas (por ejemplo, traducciones hechas a mano o claves que ya no están en el fuente). Solo se sobrescriben los textos que llegan traducidos.
- Los archivos se escriben de forma atómica: nunca quedan a medias ni se borran.
- Conviene versionar todos estos archivos en git, incluido `.translate-projects.json`.

## Caché

- Los textos fuente solo se suben si cambiaron. El backend lo comprueba con una versión (hash) del archivo.
- `lang/.translate-projects.json` guarda la versión traducida de cada idioma. Si el fuente no cambió y `lang/{idioma}.json` existe, ese idioma se omite:

  ```
  ✅ Textos fuente sin cambios, no se suben
  ✅ en está al día, se omite
  ⏱ total 0.2s · 1 peticiones HTTP
  ```

- Un idioma con textos sin traducir no se marca en la caché: se reintenta en la próxima corrida.
- Para traducir de nuevo, usa `php artisan translate --force`. Para un solo idioma: `php artisan translate en --force`.

## Errores comunes

| Mensaje | Causa | Solución |
|---|---|---|
| `❌ TRANSLATE_PROJECTS_API_KEY no está configurada` | Falta la variable en el `.env` | Añádela. Si usas `config:cache`, ejecuta `config:clear` |
| `❌ No se pudieron subir los textos fuente: API Error (400): INVALID_INPUT Token no válido` | La API Key no existe o está desactivada | Copia de nuevo la API Key del proyecto |
| `⚠️ en: 3 textos sin traducción. Revisa que el proyecto tenga modelo y API key de IA configurados.` | El proyecto no tiene la IA configurada (`MISSING_API_KEY`) o la traducción quedó parcial | Configura el modelo y la API key de IA del proyecto y vuelve a ejecutar `php artisan translate`: los textos que faltan se reintentan solos |
| `API Error (400): INVALID_INPUT El idioma de origen no es el predeterminado` | `default_locale` / `APP_LOCALE` no coincide con el idioma por defecto del proyecto | Usa el mismo idioma en los dos lados |
| `❌ No se pudo iniciar la traducción de fr: ... El idioma de destino no está activo en tu proyecto` | El idioma está desactivado en el proyecto | Actívalo en el proyecto o quítalo de `target_languages` |
| `❌ No se encontró o no es válido .../lang/es.json` | Aún no se extrajeron los textos | Ejecuta `php artisan translate:scan` |
| `⚠️ ... cambiaron los :placeholders, se deja sin traducir` | La IA alteró un `:placeholder` | Esa traducción se descarta para no romper el reemplazo de Laravel. Corrígela a mano en `lang/{idioma}.json` |
| `API Error (502)` / `Request failed: ...` | Backend caído o fallo de red | Se reintenta 3 veces. Si sigue fallando, ese idioma queda como estaba y el comando continúa con los demás |

Ante cualquier error, los archivos existentes no se modifican.

## Limitaciones

- Solo se traducen los textos usados como clave (archivos JSON). Los archivos de grupo `lang/{idioma}/*.php` no se suben.
- No se detectan claves dinámicas, como `__($variable)`.

## Desarrollo

```bash
composer install
composer test
```

Los tests usan Orchestra Testbench con la API simulada (`Http::fake`), así que no hacen peticiones de red.

## Licencia

MIT © Neider Ruiz
