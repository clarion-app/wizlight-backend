<?php

namespace ClarionApp\WizlightBackend;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\WizlightBackend\Jobs\BulbDiscovery;
use ClarionApp\WizlightBackend\Commands\WizlightDiscover;

class WizlightBackendServiceProvider extends ClarionPackageServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        parent::register();

        $this->commands([
            WizlightDiscover::class,
        ]);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(__DIR__.'/Migrations');

        if(!$this->app->routesAreCached())
        {
            require __DIR__.'/Routes.php';
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->call(function() {
                $result = shell_exec('pgrep -c -f "php artisan queue:work --queue=default"');
                if($result == "2\n")
                {
                    dispatch(new BulbDiscovery());
                }
            })->everyFiveSeconds();
        });
    }
}
