<?php

namespace ClarionApp\WizlightBackend;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\WizlightBackend\Jobs\BulbDiscovery;
use ClarionApp\WizlightBackend\Commands\WizlightDiscover;
use Illuminate\Support\Facades\Log;

class WizlightBackendServiceProvider extends ClarionPackageServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/wizlight.php', 'wizlight');

        $this->app->singleton(\ClarionApp\WizlightBackend\Transport\UdpTransport::class, function ($app) {
            $broadcastAddress = $app['config']->get('wizlight.broadcast_address', '255.255.255.255');
            $udpPort = $app['config']->get('wizlight.udp_port', 38899);
            return new \ClarionApp\WizlightBackend\Transport\SocketUdpTransport($broadcastAddress, $udpPort);
        });

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

        $this->publishes([
            __DIR__.'/../config/wizlight.php' => config_path('wizlight.php'),
        ], 'clarion-config');

        if(!$this->app->routesAreCached())
        {
            require __DIR__.'/Routes.php';
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->call(function() {
                BulbDiscovery::dispatchSync();
            })->everyMinute();
        });
    }
}
