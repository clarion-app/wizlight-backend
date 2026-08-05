<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Controllers\BulbController;
use ClarionApp\WizlightBackend\Controllers\RoomController;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * The request thread may write to the wire, but must never read from it.
 *
 * Sending a setPilot is a fire-and-forget datagram costing microseconds, so it
 * happens inline — deferring it only put every command behind the periodic
 * discovery and status sweeps on a shared queue. What must never happen on the
 * request thread is a *read*: receive() waits for a reply, and a light that is
 * unplugged never sends one, which is the case that made light control feel
 * slow in the first place.
 *
 * So a transport that records every touch is bound into the container, and a
 * bulb update and a room update are driven through their controllers: sends are
 * expected, a receive is a failure.
 */
class NoBlockingReadDuringHttpWriteTest extends TestCase
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

        // A discarding queue, deliberately. Under the default `sync` driver a
        // queued dispatch runs inline anyway, so these tests could not tell
        // "sent during the request" from "handed to a worker" — the very
        // distinction they exist to pin down. With `null`, anything merely
        // enqueued is thrown away and the send assertions below go red.
        $app['config']->set('queue.default', 'null');
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/Migrations');
    }

    private function recordingTransport(): object
    {
        $recorder = new class implements UdpTransport {
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

        $this->app->instance(UdpTransport::class, $recorder);

        return $recorder;
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
    public function updating_a_bulb_sends_without_reading()
    {
        $recorder = $this->recordingTransport();
        $bulb = $this->makeBulb();

        Event::fake();

        $controller = new BulbController(new WizlightService());
        $controller->update(Request::create('/', 'PUT', ['state' => true]), (string) $bulb->id);

        $this->assertContains('send', $recorder->touches, 'The command must reach the light in the request');
        $this->assertNotContains('receive', $recorder->touches, 'An HTTP bulb write must never wait for a reply');
    }

    /** @test */
    public function updating_a_room_sends_to_every_bulb_without_reading()
    {
        $recorder = $this->recordingTransport();

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

        Event::fake();

        $controller = new RoomController(new WizlightService());
        $controller->update(Request::create('/', 'PUT', ['state' => true]), (string) $room->id);

        $sends = array_filter($recorder->touches, fn ($touch) => $touch === 'send');
        $this->assertCount(5, $sends, 'Every member of the room must be commanded in the request');
        $this->assertNotContains('receive', $recorder->touches, 'A room fan-out must never wait for a reply');
    }

    /** @test */
    public function the_intended_state_is_persisted_before_the_response_returns()
    {
        // FR-003: the browser can render the new state immediately because the
        // row already holds it, not because the device confirmed anything.
        $this->recordingTransport();
        $bulb = $this->makeBulb(['state' => false, 'dimming' => 50]);

        Event::fake();

        $controller = new BulbController(new WizlightService());
        $controller->update(Request::create('/', 'PUT', ['state' => true, 'dimming' => 90]), (string) $bulb->id);

        $stored = Bulb::find($bulb->id);
        $this->assertTrue((bool) $stored->state);
        $this->assertEquals(90, $stored->dimming);
    }
}
