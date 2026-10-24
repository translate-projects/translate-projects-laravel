<?php

namespace Neiderruiz\TranslateProjectsLaravel\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class TranslateScanCommand extends Command
{
    protected $signature = 'translate:scan';
    protected $description = 'Extrae los textos de __(), trans() y @lang() a lang/{idioma base}.json';

    public function handle(): int
    {
        $source = TranslateCommand::fileLocale(config('translate-projects.default_locale'));
        $paths = array_filter(array_map('base_path', (array) config('translate-projects.scan_paths')), 'is_dir');

        $found = [];
        if ($paths) {
            foreach (Finder::create()->files()->in($paths)->name('*.php') as $file) {
                foreach (self::extract($file->getContents()) as $key) {
                    // Claves de grupo (auth.failed → lang/es/auth.php) y de paquetes (pkg::file.key) no van al JSON
                    if ($key === '' || str_contains($key, '::') || is_file(lang_path("$source/" . strtok($key, '.') . '.php'))) {
                        continue;
                    }
                    $found[$key] = $key;
                }
            }
        }

        if (!$found) {
            $this->warn('⚠️ No se encontraron textos de traducción.');
            return self::SUCCESS;
        }

        $file = lang_path("$source.json");
        $existing = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        if (!is_array($existing)) {
            $this->error("❌ $file no es un JSON válido, no se modifica.");
            return self::FAILURE;
        }

        // Se conservan los textos existentes (también los que ya no aparecen en el código)
        $merged = $existing + $found;
        ksort($merged);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        $this->info(sprintf('✅ %d textos (%d nuevos) en %s', count($merged), count($merged) - count($existing), $file));
        return self::SUCCESS;
    }

    /** Devuelve las claves literales de __('...'), trans('...'), trans_choice('...') y @lang('...'). */
    public static function extract(string $content): array
    {
        preg_match_all('/(?:\b__|\btrans|\btrans_choice|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*[,)]/', $content, $matches, PREG_SET_ORDER);

        return array_map(
            fn ($m) => isset($m[2]) && $m[2] !== '' ? stripcslashes($m[2]) : str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]),
            $matches
        );
    }
}
