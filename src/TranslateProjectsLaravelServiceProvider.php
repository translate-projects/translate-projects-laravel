<?php

namespace Neiderruiz\TranslateProjectsLaravel;

use Illuminate\Support\ServiceProvider;

class TranslateProjectsLaravelServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/translate-projects.php', 'translate-projects');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/translate-projects.php' => config_path('translate-projects.php'),
            ], 'translate-config');

            $this->commands([
                Commands\TranslateScanCommand::class,
                Commands\TranslateCommand::class,
            ]);
        }
    }
}
