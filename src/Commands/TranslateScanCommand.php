<?php

namespace Neiderruiz\TranslateProjectsLaravel\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Illuminate\Support\Facades\File;

class TranslateScanCommand extends Command
{
    protected $signature = 'translate:scan';
    protected $description = 'Escanea el código y extrae todas las claves de traducción usadas con __() o @lang()';

    public function handle()
    {
        $this->info('🔍 Escaneando el proyecto en busca de claves de traducción...');

        $locale = config('app.locale'); // Obtener el idioma predeterminado
        $langPath = lang_path($locale); // Ruta de la carpeta del idioma

        // Crear la carpeta si no existe
        if (!File::exists($langPath)) {
            File::makeDirectory($langPath, 0755, true);
        }

        $translations = $this->scanProjectForTranslations(base_path());

        if (empty($translations)) {
            $this->warn('⚠️ No se encontraron claves de traducción.');
            return;
        }

        $outputFile = $langPath . '/translations.php';
        $content = $this->formatArrayToPhpFile($translations);

        File::put($outputFile, $content);

        $this->info('✅ Claves de traducción extraídas con éxito.');
        $this->info('📂 Archivo generado: ' . $outputFile);
    }

    /**
     * Escanea los archivos PHP y Blade para extraer claves de traducción.
     */
    private function scanProjectForTranslations($path)
    {
        $translations = [];

        $directory = new RecursiveDirectoryIterator($path);
        $iterator = new RecursiveIteratorIterator($directory);
        $files = new RegexIterator($iterator, '/\.(php|blade\.php)$/', RegexIterator::MATCH);

        foreach ($files as $file) {
            $content = file_get_contents($file->getPathname());

            // Buscar __() y @lang()
            preg_match_all("/__\(['\"](.*?)['\"]\)/", $content, $matches1);
            preg_match_all("/@lang\(['\"](.*?)['\"]\)/", $content, $matches2);

            $matches = array_merge($matches1[1], $matches2[1]);

            foreach ($matches as $key) {
                $translations[$key] = $translations[$key] ?? $key;
            }
        }

        return $translations;
    }

    /**
     * Formatea un array como código PHP con la sintaxis correcta de Laravel.
     */
    private function formatArrayToPhpFile(array $translations)
    {
        $exportedArray = var_export($translations, true);

        // Reemplazar `array(` por `[`, y `)` por `]` para usar la sintaxis moderna de arrays
        $formattedArray = preg_replace(['/array \(/', '/\)$/'], ['[', ']'], $exportedArray);

        return "<?php\n\nreturn " . $formattedArray . ";\n";
    }
}
