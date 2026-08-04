<?php

namespace ClarionApp\WizlightBackend;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\WizlightBackend\Jobs\BulbDiscovery;
use ClarionApp\WizlightBackend\Jobs\BulbStatusCheck;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\BulbLastSeen;
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

        // FR-011: the last-seen cascade lives with the model, not with a caller,
        // so it holds for every deletion path — HTTP, console, future bulk
        // delete. Registered here because EloquentMultiChainBridge's own boot()
        // makes a class-level boot()/booted() hook on Bulb impractical. It fires
        // on soft deletes too, which a database ON DELETE CASCADE cannot see.
        Bulb::deleting(function (Bulb $bulb) {
            BulbLastSeen::where('bulb_id', $bulb->id)->delete();
        });

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // FR-006b: $schedule->job() enqueues and returns. Nothing in this
            // block may do I/O — no dispatchSync(), no ->handle(), no socket —
            // so a slow or unreachable device can never delay the scheduler.
            $schedule->job(new BulbDiscovery())->everyMinute();
            $schedule->job(new BulbStatusCheck())->everyMinute();
        });
    }
}
