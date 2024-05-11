<?php

namespace ClarionApp\WizlightBackend;

use Illuminate\Support\ServiceProvider;
use ClarionApp\WizlightBackend\Commands\WizlightDiscover;

class WizlightBackendServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->commands([
            WizlightDiscover::class,
        ]);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Migrations');
    }
}
