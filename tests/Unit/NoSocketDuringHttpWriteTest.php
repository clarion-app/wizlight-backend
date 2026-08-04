<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Controllers\BulbController;
use ClarionApp\WizlightBackend\Controllers\RoomController;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/**
 * T012b — the negative half of the dispatch assertion (FR-001/FR-002).
 *
 * "SendBulbCommand was dispatched" does not catch an inline send that happens
 * *as well*, which is exactly the shape D1 had. So a transport that fails the
 * test the moment it is touched is bound into the container, and a bulb update
 * and a room update are driven through their controllers.
 */
class NoSocketDuringHttpWriteTest extends TestCase
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
        $app['config']->set('clarion.node_id', 'test-node-id');
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/Migrations');
    }

    private function forbidSockets(): object
    {
        $forbidden = new class implements UdpTransport {
            public array $touches = [];

            public function send(mixed $message, array $targets): void
            {
                $this->touches[] = 'send';
            }

            public function receive(float $timeout): ?array
            {
                $this->touches[] = 'receive';
                return null;
            }

            public function close(): void
            {
                $this->touches[] = 'close';
            }
        };

        $this->app->instance(UdpTransport::class, $forbidden);

        return $forbidden;
    }

    private function makeBulb(array $attrs = []): Bulb
    {
        return Bulb::create(array_merge([
            'local_node_id' => 'test-node-id',
            'local_node_seen_at' => now(),
            'mac' => 'aa:bb:cc:dd:ee:' . str_pad((string) random_int(10, 99), 2, '0'),
            'ip' => '192.168.1.10',
            'name' => 'Test Bulb',
            'state' => false,
            'dimming' => 50,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'temperature' => 2700,
        ], $attrs));
    }

    /** @test */
    public function updating_a_bulb_performs_no_socket_work()
    {
        $forbidden = $this->forbidSockets();
        $bulb = $this->makeBulb();

        Bus::fake();
        Event::fake();

        $controller = new BulbController(new WizlightService());
        $controller->update(Request::create('/', 'PUT', ['state' => true]), (string) $bulb->id);

        $this->assertSame([], $forbidden->touches, 'An HTTP bulb write must not touch the wire');
        Bus::assertDispatched(SendBulbCommand::class);
    }

    /** @test */
    public function updating_a_room_performs_no_socket_work_for_any_of_its_bulbs()
    {
        $forbidden = $this->forbidSockets();

        $room = Room::create([
            'local_node_id' => 'test-node-id',
            'name' => 'Living Room',
            'state' => false,
            'dimming' => 50,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'temperature' => 2700,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->makeBulb([
                'mac' => 'aa:bb:cc:dd:ff:0' . $i,
                'ip' => '192.168.1.2' . $i,
                'room_id' => $room->id,
            ]);
        }

        Bus::fake();
        Event::fake();

        $controller = new RoomController(new WizlightService());
        $controller->update(Request::create('/', 'PUT', ['state' => true]), (string) $room->id);

        $this->assertSame([], $forbidden->touches, 'A room fan-out must not touch the wire on the request thread');
        Bus::assertDispatched(SendBulbCommand::class, 5);
    }

    /** @test */
    public function the_intended_state_is_persisted_before_the_response_returns()
    {
        // FR-003: the browser can render the new state immediately because the
        // row already holds it, not because the device confirmed anything.
        $this->forbidSockets();
        $bulb = $this->makeBulb(['state' => false, 'dimming' => 50]);

        Bus::fake();
        Event::fake();

        $controller = new BulbController(new WizlightService());
        $controller->update(Request::create('/', 'PUT', ['state' => true, 'dimming' => 90]), (string) $bulb->id);

        $stored = Bulb::find($bulb->id);
        $this->assertTrue((bool) $stored->state);
        $this->assertEquals(90, $stored->dimming);
    }
}
