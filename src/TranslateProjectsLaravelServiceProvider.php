<?php

namespace Neiderruiz\TranslateProjectsLaravel;

use Illuminate\Support\ServiceProvider;

class TranslateProjectsLaravelServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/translate-projects.php', 'translate-projects');
        $this->commands([
            \Neiderruiz\TranslateProjectsLaravel\Commands\TranslateScanCommand::class,
            \Neiderruiz\TranslateProjectsLaravel\Commands\TranslateCommand::class,
        ]);
    
    }

    public function boot()
    {
        $configPath = config_path('translate-projects.php');

        if (!file_exists($configPath)) {
            $this->publishes([
                __DIR__.'/config/translate-projects.php' => $configPath,
            ], 'translate-config');
    
            // Copiar automáticamente si no existe
            copy(__DIR__.'/config/translate-projects.php', $configPath);
        }
    

    }
}
