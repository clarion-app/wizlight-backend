<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Jobs\BulbStatusCheck;
use ClarionApp\WizlightBackend\Jobs\CheckBulbStatus;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class BulbStatusCheckTest extends TestCase
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
        $app['config']->set('cache.stores.testing', [
            'driver' => 'array',
        ]);
        $app['config']->set('cache.default', 'testing');
        $app['config']->set('wizlight.broadcast_address', '255.255.255.255');
        $app['config']->set('wizlight.udp_wait_time', 30.0);
        $app['config']->set('wizlight.phone_mac', 'AAAAAAAAAAAA');
        $app['config']->set('wizlight.udp_port', 38899);
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/Migrations');
    }

    /** @test */
    public function orchestrator_dispatches_per_bulb_jobs()
    {
        Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:10',
            'ip' => '192.168.1.10',
            'name' => 'Bulb 1',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -50,
        ]);

        Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:11',
            'ip' => '192.168.1.11',
            'name' => 'Bulb 2',
            'state' => true,
            'dimming' => 80,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'signal' => -60,
        ]);

        Bus::fake(CheckBulbStatus::class);

        $job = new BulbStatusCheck();
        $job->handle();

        Bus::assertDispatched(CheckBulbStatus::class, 2);
    }

    /** @test */
    public function orchestrator_skips_when_lock_is_held()
    {
        // A bulb must exist, or this passes whether the lock works or not.
        Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:14',
            'ip' => '192.168.1.14',
            'name' => 'Bulb 5',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -50,
        ]);

        Bus::fake(CheckBulbStatus::class);

        // Hold the lock for the duration of the call, rather than taking and
        // releasing it beforehand — the sweep must observe it held.
        $lock = Cache::lock('bulb-status-check', 30);
        $this->assertTrue($lock->get(), 'Precondition: the test holds the lock');

        try {
            (new BulbStatusCheck())->handle();
        } finally {
            $lock->release();
        }

        Bus::assertNotDispatched(CheckBulbStatus::class);
    }

    /** @test */
    public function orchestrator_returns_immediately_rather_than_waiting_for_the_lock()
    {
        // FR-007 is "skip", not "queue behind". Blocking on the lock would make
        // sweeps pile up on one another, which is the defect being fixed.
        Bus::fake(CheckBulbStatus::class);

        $lock = Cache::lock('bulb-status-check', 30);
        $lock->get();

        try {
            $start = microtime(true);
            (new BulbStatusCheck())->handle();
            $elapsed = microtime(true) - $start;
        } finally {
            $lock->release();
        }

        $this->assertLessThan(0.5, $elapsed, 'A contended sweep must return at once, not block');
    }

    /** @test */
    public function orchestrator_queues_per_bulb_jobs_rather_than_running_them_inline()
    {
        // FR-006: the whole bound comes from the per-bulb jobs going onto the
        // queue. Bus::dispatchSync() in the orchestrator's loop would run each
        // check inline — N × 2s in one process — while still satisfying a naive
        // "was CheckBulbStatus dispatched?" assertion.
        foreach (['AA:BB:CC:DD:EE:15', 'AA:BB:CC:DD:EE:16', 'AA:BB:CC:DD:EE:17'] as $i => $mac) {
            Bulb::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
                'mac' => $mac,
                'ip' => '192.168.2.' . (10 + $i),
                'name' => 'Bulb ' . $i,
                'state' => false,
                'dimming' => 100,
                'red' => 0,
                'green' => 0,
                'blue' => 0,
                'signal' => -50,
            ]);
        }

        // A transport that fails the test if it is touched: the orchestrator
        // contacts no device, and inline per-bulb jobs would contact three.
        $forbidden = new class implements UdpTransport {
            public bool $touched = false;
            public function send(mixed $message, array $targets): void { $this->touched = true; }
            public function receive(float $timeout): ?array { $this->touched = true; return null; }
            public function close(): void { $this->touched = true; }
        };
        $this->app->instance(UdpTransport::class, $forbidden);

        Queue::fake();

        (new BulbStatusCheck())->handle();

        Queue::assertPushed(CheckBulbStatus::class, 3);
        $this->assertFalse($forbidden->touched, 'The orchestrator itself must perform zero round trips');

        // The discriminator. Bus::dispatchSync() on a ShouldQueue job pins it to
        // the 'sync' connection, which runs it inline in this process — N × 2s,
        // the linear sweep FR-006 exists to prevent. Under Queue::fake() both
        // forms look "dispatched", so the connection is what distinguishes them.
        Queue::assertPushed(CheckBulbStatus::class, function (CheckBulbStatus $job) {
            return $job->connection !== 'sync';
        });
        Queue::assertNotPushed(CheckBulbStatus::class, function (CheckBulbStatus $job) {
            return $job->connection === 'sync';
        });
    }

    /** @test */
    public function orchestrator_completes_within_bounded_duration()
    {
        Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:12',
            'ip' => '192.168.1.12',
            'name' => 'Bulb 3',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -50,
        ]);

        Bus::fake(CheckBulbStatus::class);

        $start = microtime(true);
        $job = new BulbStatusCheck();
        $job->handle();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, 'Orchestrator should complete within a bounded duration');
    }

    /** @test */
    public function db_state_equals_mock_transport_response_after_check()
    {
        // Use sync queue driver so dispatched jobs execute immediately.
        $this->app['config']->set('queue.default', 'sync');

        $bulb = Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:13',
            'ip' => '192.168.1.13',
            'name' => 'Bulb 4',
            'state' => false,
            'dimming' => 50,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -80,
        ]);

        $transport = $this->createMock(UdpTransport::class);
        $transport->method('receive')->willReturn([
            [
                'result' => [
                    'mac' => 'AA:BB:CC:DD:EE:13',
                    'state' => true,
                    'dimming' => 90,
                    'r' => 200,
                    'g' => 100,
                    'b' => 50,
                    'rssi' => -35,
                ],
                'from' => '192.168.1.13',
            ],
        ]);
        $transport->method('send');
        $transport->method('close');

        app()->bind(UdpTransport::class, function () use ($transport) {
            return $transport;
        });

        $job = new BulbStatusCheck();
        $job->handle();

        $bulb->refresh();
        $this->assertTrue((bool) $bulb->state, 'DB state should match mock transport response');
        $this->assertEquals(90, $bulb->dimming, 'DB dimming should match mock transport response');
        $this->assertEquals(200, $bulb->red, 'DB red should match mock transport response');
        $this->assertEquals(100, $bulb->green, 'DB green should match mock transport response');
        $this->assertEquals(50, $bulb->blue, 'DB blue should match mock transport response');
        $this->assertEquals(-35, $bulb->signal, 'DB signal should match mock transport response');
    }
}
