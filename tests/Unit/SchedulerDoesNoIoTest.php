<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Jobs\BulbDiscovery;
use ClarionApp\WizlightBackend\Jobs\BulbStatusCheck;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * FR-006b / SC-005b: the schedule block enqueues and nothing else.
 *
 * Behavioural, not source-text — a transport double that fails the test if it
 * is touched at all is bound into the container, and the registered scheduled
 * events are then run. A `dispatchSync()` or a direct `->handle()` in the
 * schedule block reaches that transport and trips the guard.
 */
class SchedulerDoesNoIoTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \ClarionApp\WizlightBackend\WizlightBackendServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/Migrations');
    }

    /**
     * @return array<int, \Illuminate\Console\Scheduling\Event>
     */
    private function wizlightScheduledEvents(): array
    {
        $schedule = $this->app->make(Schedule::class);

        return array_values(array_filter(
            $schedule->events(),
            fn ($event) => str_contains($event->getSummaryForDisplay(), 'Wizlight')
                || str_contains($event->getSummaryForDisplay(), 'Bulb')
        ));
    }

    /** @test */
    public function both_sweeps_are_registered_on_the_schedule()
    {
        $summaries = array_map(
            fn ($event) => $event->getSummaryForDisplay(),
            $this->wizlightScheduledEvents()
        );

        $this->assertContains(BulbDiscovery::class, $summaries, 'Discovery must be scheduled');
        $this->assertContains(BulbStatusCheck::class, $summaries, 'The status sweep must be scheduled');
    }

    /** @test */
    public function scheduled_entry_points_perform_no_socket_operations()
    {
        $forbidden = new class implements UdpTransport {
            public bool $touched = false;

            public function send(mixed $message, array $targets): void
            {
                $this->touched = true;
            }

            public function receive(float $timeout): ?array
            {
                $this->touched = true;
                return null;
            }

            public function close(): void
            {
                $this->touched = true;
            }
        };

        $this->app->instance(UdpTransport::class, $forbidden);
        Queue::fake();

        foreach ($this->wizlightScheduledEvents() as $event) {
            $event->run($this->app);
        }

        $this->assertFalse(
            $forbidden->touched,
            'The schedule block performed UDP I/O — it must enqueue and return (no dispatchSync, no ->handle())'
        );
    }

    /** @test */
    public function scheduled_entry_points_enqueue_their_work_rather_than_running_it()
    {
        Queue::fake();

        foreach ($this->wizlightScheduledEvents() as $event) {
            $event->run($this->app);
        }

        Queue::assertPushed(BulbDiscovery::class);
        Queue::assertPushed(BulbStatusCheck::class);
    }

    /** @test */
    public function scheduled_entry_points_return_promptly()
    {
        Queue::fake();

        $start = microtime(true);
        foreach ($this->wizlightScheduledEvents() as $event) {
            $event->run($this->app);
        }
        $elapsedMs = (microtime(true) - $start) * 1000;

        // SC-005b: under 100 ms, having enqueued and done nothing else.
        $this->assertLessThan(100, $elapsedMs, 'Scheduled entry points must return in under 100ms');
    }
}
