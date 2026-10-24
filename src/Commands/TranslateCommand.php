<?php

namespace Neiderruiz\TranslateProjectsLaravel\Commands;

use Illuminate\Console\Command;
use Neiderruiz\TranslateProjectsLaravel\Helpers\HashHelper;
use Neiderruiz\TranslateProjectsLaravel\Services\TranslationApi;

class TranslateCommand extends Command
{
    protected $signature = 'translate {locale? : Traduce solo este idioma} {--force : Traduce aunque no haya cambios}';
    protected $description = 'Traduce lang/{idioma base}.json a los idiomas configurados con Translate Projects';

    // Textos que el backend nunca guarda (helpers/ignore_list.py), así que nunca vuelven traducidos
    private const IGNORED = ['', '.', ',', ':', ';', '...'];
    private const CACHE_FILE = '.translate-projects.json';

    public function handle(): int
    {
        $started = microtime(true);
        $apiKey = config('translate-projects.api_key');
        $source = self::fileLocale(config('translate-projects.default_locale'));
        $targets = array_map([self::class, 'fileLocale'], (array) config('translate-projects.target_languages'));

        if (!$apiKey) {
            $this->error('❌ TRANSLATE_PROJECTS_API_KEY no está configurada (.env o config/translate-projects.php).');
            return self::FAILURE;
        }

        if ($locale = $this->argument('locale')) {
            $locale = self::fileLocale($locale);
            if (!in_array($locale, $targets)) {
                $this->error("❌ '$locale' no está en target_languages: " . implode(', ', $targets));
                return self::FAILURE;
            }
            $targets = [$locale];
        }
        $targets = array_values(array_diff($targets, [$source]));

        $sourceFile = lang_path("$source.json");
        $texts = is_file($sourceFile) ? json_decode(file_get_contents($sourceFile), true) : null;
        if (!is_array($texts)) {
            $this->error("❌ No se encontró o no es válido $sourceFile. Ejecuta primero: php artisan translate:scan");
            return self::FAILURE;
        }

        // {hash: texto}, la misma clave que calcula el backend; así no hacen falta custom_key
        $sources = [];
        foreach ($texts as $text) {
            if (is_string($text) && !in_array($text, self::IGNORED, true)) {
                $sources[HashHelper::generateHashText($text)] = $text;
            }
        }
        if (!$sources) {
            $this->warn("⚠️ $sourceFile no tiene textos para traducir.");
            return self::SUCCESS;
        }
        $this->info('📂 ' . count($sources) . " textos en $source");

        $api = app()->makeWith(TranslationApi::class, [
            'token' => $apiKey,
            'baseUrl' => config('translate-projects.api_url'),
        ]);
        $apiSource = self::apiLocale($source);
        $routeFile = "$source.json";
        $hashes = array_keys($sources);
        sort($hashes);
        $version = HashHelper::generateHashText(json_encode($hashes));

        if (!$this->syncSources($api, $sources, $apiSource, $routeFile, $version)) {
            return self::FAILURE;
        }

        $cachePath = lang_path(self::CACHE_FILE);
        $cache = json_decode(@file_get_contents($cachePath) ?: '{}', true) ?: [];
        $failed = false;

        foreach ($targets as $target) {
            $targetFile = lang_path("$target.json");
            if (!$this->option('force') && ($cache[$target] ?? null) === $version && is_file($targetFile)) {
                $this->info("✅ $target está al día, se omite");
                continue;
            }

            $result = $this->translateLanguage($api, $texts, $apiSource, $target, $routeFile, $targetFile);
            if ($result === null) {
                $failed = true;
                continue;
            }
            // Si faltan textos no se cachea, para reintentarlos en la próxima corrida
            if ($result['missing']) {
                unset($cache[$target]);
            } else {
                $cache[$target] = $version;
            }
            file_put_contents($cachePath, json_encode($cache, JSON_PRETTY_PRINT) . "\n");
            $this->line(sprintf('   %s: %d traducidos, %d sin traducir, %d rechazados · %.1fs',
                $target, $result['filled'], $result['missing'], $result['rejected'], $result['seconds']));
        }

        $this->line(sprintf('⏱ total %.1fs · %d peticiones HTTP', microtime(true) - $started, $api->requestsMade));
        if ($failed) {
            $this->error('❌ Algunos idiomas no se pudieron traducir.');
            return self::FAILURE;
        }
        $this->info('🎉 Traducción completada.');
        return self::SUCCESS;
    }

    /** Sube los textos fuente salvo que el backend ya tenga esta misma versión. */
    private function syncSources(TranslationApi $api, array $sources, string $apiSource, string $routeFile, string $version): bool
    {
        $fileHash = HashHelper::generateHashText($routeFile);
        $changed = $api->validateChangesFiles([$fileHash => $version]);
        if ($changed !== null && !array_key_exists($fileHash, $changed)) {
            $this->info('✅ Textos fuente sin cambios, no se suben');
            return true;
        }

        $taskId = $api->syncSources($sources, $apiSource, $routeFile, $version);
        if (!$taskId) {
            $this->error("❌ No se pudieron subir los textos fuente: {$api->lastError}");
            return false;
        }
        if (!$api->waitTask($taskId)) {
            $this->warn("⚠️ {$api->lastError}, se continúa");
        }
        return true;
    }

    /** Traduce un idioma y escribe lang/{idioma}.json. Devuelve null sin tocar el archivo si falla. */
    private function translateLanguage(TranslationApi $api, array $texts, string $apiSource, string $target, string $routeFile, string $targetFile): ?array
    {
        $started = microtime(true);
        $apiTarget = self::apiLocale($target);
        $this->info("📝 Traduciendo a $target...");

        $taskId = $api->makeTranslations($apiSource, $apiTarget, $routeFile);
        if (!$taskId) {
            $this->error("❌ No se pudo iniciar la traducción de $target: {$api->lastError}");
            return null;
        }
        if (!$api->waitTask($taskId)) {
            $this->warn("⚠️ $target: {$api->lastError}, se descarga lo que haya");
        }

        $byHash = $api->getTranslations($apiTarget);
        if ($byHash === null) {
            $this->error("❌ No se recibieron las traducciones de $target: {$api->lastError}");
            return null;
        }

        // Se parte del archivo existente para no perder textos traducidos a mano ni claves que ya no están en el fuente
        $existing = is_file($targetFile) ? json_decode(file_get_contents($targetFile), true) : [];
        $output = is_array($existing) ? $existing : [];
        $filled = $missing = 0;
        $rejected = [];

        foreach ($texts as $key => $text) {
            if (!is_string($text) || in_array($text, self::IGNORED, true)) {
                continue;
            }
            $translation = $byHash[HashHelper::generateHashText($text)] ?? null;
            if (!is_string($translation) || $translation === '') {
                $missing++;
                continue;
            }
            // Un :placeholder cambiado rompe el reemplazo de Laravel, así que se deja el texto sin traducir
            if (self::placeholders($text) !== self::placeholders($translation)) {
                $rejected[] = $key;
                continue;
            }
            $output[$key] = $translation;
            $filled++;
        }

        if ($missing) {
            $this->warn("⚠️ $target: $missing textos sin traducción. Revisa que el proyecto tenga modelo y API key de IA configurados.");
        }
        foreach ($rejected as $key) {
            $this->warn("⚠️ $target: cambiaron los :placeholders, se deja sin traducir: $key");
        }

        // Escritura atómica: un fallo a mitad no deja un JSON corrupto
        $tmp = "$targetFile.tmp";
        file_put_contents($tmp, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        rename($tmp, $targetFile);

        return [
            'filled' => $filled,
            'missing' => $missing,
            'rejected' => count($rejected),
            'seconds' => microtime(true) - $started,
        ];
    }

    private static function placeholders(string $text): array
    {
        preg_match_all('/:[a-zA-Z_]\w*/', $text, $matches);
        $found = array_map('strtolower', $matches[0]);
        sort($found);
        return $found;
    }

    /** "pt-br" / "pt_BR" → nombre de archivo de Laravel "pt_BR". */
    public static function fileLocale(string $code): string
    {
        [$lang, $region] = array_pad(preg_split('/[-_]/', $code, 2), 2, null);
        if (!$region) {
            return strtolower($lang);
        }
        return strtolower($lang) . '_' . (strlen($region) === 2 ? strtoupper($region) : ucfirst(strtolower($region)));
    }

    /** "pt_BR" → código del backend "pt-BR". */
    public static function apiLocale(string $code): string
    {
        return str_replace('_', '-', self::fileLocale($code));
    }
}
