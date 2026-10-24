<?php

namespace Neiderruiz\TranslateProjectsLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Neiderruiz\TranslateProjectsLaravel\Services\TranslationApi;
use Neiderruiz\TranslateProjectsLaravel\Helpers\HashHelper;

class TranslateCommand extends Command
{
    protected $signature = 'translate';
    protected $description = 'Traduce el contenido del proyecto a otros idiomas y lo guarda en la carpeta lang/{idioma}';

    public function handle()
    {
        $this->info('🔍 Iniciando la traducción del proyecto...');

        $defaultLang = config('translate-projects.default_locale'); // Idioma base
        $targetLangs = config('translate-projects.target_languages'); // Idiomas a traducir
        $apiKey = config('translate-projects.api_key');

        // Validar si la API Key está configurada
        if (!$apiKey) {
            $this->error('❌ No se ha configurado una API Key en config/translate-projects.php');
            return;
        }

        // Validar si el archivo de traducciones existe
        $translationFile = lang_path("{$defaultLang}/translations.php");

        if (!File::exists($translationFile)) {
            $this->error("❌ No se encontró el archivo de traducciones en {$translationFile}");
            return;
        }

        // Cargar las traducciones base
        $translations = require $translationFile;

        $api = new TranslationApi($apiKey);

        foreach ($targetLangs as $targetLang) {
            $this->info("🔍 Traduciendo al idioma {$targetLang}...");

            $send = [];

            foreach ($translations as $key => $value) {
                $send[HashHelper::generateHashText($key)] = $value;
            }

            // Hacer la solicitud a la API
            $translatedData = $api->syncSources([
                'sourceLang' => $defaultLang,
                'targetLang' => $targetLang,
                'route_file' => 'translations.php',
                'typeProject' => 'laravel',
                'cache_hash' => null,
                'data' => $send,
            ]);

            // Verificar si la traducción fue exitosa
            if (!$translatedData) {
                $this->error("❌ No se pudo traducir al idioma {$targetLang}");
                continue;
            }

            // Guardar las traducciones en lang/{idioma}/translations.php
            // $langPath = lang_path($targetLang);

            // if (!File::exists($langPath)) {
            //     File::makeDirectory($langPath, 0755, true);
            // }

            // $outputFile = "{$langPath}/translations.php";
            // $content = $this->formatArrayToPhpFile($translatedData);
            // File::put($outputFile, $content);

            // $this->info("✅ Traducción guardada en: {$outputFile}");
        }

        $this->info('🎉 Traducción completada.');
    }

    /**
     * Formatea un array como código PHP con la sintaxis correcta de Laravel.
     */
    private function formatArrayToPhpFile(array $translations): string
    {
        $exportedArray = var_export($translations, true);

        // Reemplazar `array(` por `[`, y `)` por `]` para usar la sintaxis moderna de arrays
        $formattedArray = preg_replace(['/array \(/', '/\)$/'], ['[', ']'], $exportedArray);

        return "<?php\n\nreturn " . $formattedArray . ";\n";
    }
}
