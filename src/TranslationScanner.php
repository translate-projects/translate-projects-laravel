<?php

namespace Neiderruiz\TranslateProjectsLaravel;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class TranslationScanner
{
    protected string $langPath;

    public function __construct(string $langPath = null)
    {
        $this->langPath = $langPath ?? base_path('lang');
    }

    public function scan(): array
    {
        $translations = [];

        foreach ($this->getTranslationFiles() as $file) {
            $locale = basename(dirname($file)); // "en", "es", etc.
            $key = pathinfo($file, PATHINFO_FILENAME);

            $data = require $file;

            if (is_array($data)) {
                $translations[$locale][$key] = $data;
            }
        }

        return $translations;
    }

    public function exportJson(string $outputPath = 'translations.json'): void
    {
        $translations = $this->scan();
        file_put_contents($outputPath, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function getTranslationFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->langPath));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
