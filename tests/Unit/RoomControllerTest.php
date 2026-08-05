<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Controllers\RoomController;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

class RoomControllerTest extends TestCase
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

    private function createRoom(array $attrs = []): Room
    {
        $defaults = [
            'name' => 'Test Room',
            'state' => false,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 50,
            'temperature' => 2700,
            'local_node_id' => 'other-node',
        ];
        $data = array_merge($defaults, $attrs);
        return Room::create($data);
    }

    private function createBulb(array $attrs = []): Bulb
    {
        $defaults = [
            'mac' => 'aa:bb:cc:dd:ee:02',
            'ip' => '192.168.1.11',
            'name' => 'Room Bulb',
            'state' => false,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 75,
            'temperature' => 2700,
            'local_node_id' => 'other-node',
            'room_id' => null,
        ];
        $data = array_merge($defaults, $attrs);
        return Bulb::create($data);
    }

    private function makeController(): RoomController
    {
        Bus::fake();
        Event::fake();
        return new RoomController(new WizlightService());
    }

    /** @test */
    public function update_returns_immediately_with_updated_room_state()
    {
        $room = $this->createRoom(['state' => false]);
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.10',
            'room_id' => (string) $room->id,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $result = $controller->update($request, (string) $room->id);

        $this->assertTrue($result->state);
        Bus::assertDispatchedSync(SendBulbCommand::class);
        Event::assertDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function update_dispatches_command_per_local_bulb()
    {
        $room = $this->createRoom(['state' => false]);
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.10',
            'room_id' => (string) $room->id,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $controller->update($request, (string) $room->id);

        Bus::assertDispatchedSync(SendBulbCommand::class, function ($job) use ($bulb) {
            return $job->bulbId === (string) $bulb->id;
        });
    }

    /** @test */
    public function update_skips_dispatch_for_remote_bulbs()
    {
        $room = $this->createRoom(['state' => false]);
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'other-node',
            'room_id' => (string) $room->id,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $controller->update($request, (string) $room->id);

        Bus::assertNotDispatchedSync(SendBulbCommand::class);
    }

    /** @test */
    public function update_fires_bulb_status_event_per_changed_bulb()
    {
        $room = $this->createRoom(['state' => false]);
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'test-node-id',
            'room_id' => (string) $room->id,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $controller->update($request, (string) $room->id);

        Event::assertDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function update_preserves_room_dimming_when_omitted()
    {
        $room = $this->createRoom(['dimming' => 50, 'red' => 255]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'red' => 100,
        ]);

        $result = $controller->update($request, (string) $room->id);

        $this->assertEquals(50, $result->dimming);
    }

    /** @test */
    public function update_preserves_bulb_brightness_when_dimming_omitted()
    {
        $room = $this->createRoom(['dimming' => 75, 'red' => 255]);
        $bulb = $this->createBulb([
            'dimming' => 75,
            'red' => 255,
            'room_id' => (string) $room->id,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'red' => 100,
        ]);

        $result = $controller->update($request, (string) $room->id);

        $this->assertEquals(75, $bulb->fresh()->dimming);
    }

    /** @test */
    public function update_returns_404_when_room_not_found()
    {
        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $response = $controller->update($request, 'nonexistent-id');

        $this->assertEquals(404, $response->status());
    }

    // ------------------------------------------------------------------
    // Phase 6 (US3): Room scene with mixed-capability members
    // ------------------------------------------------------------------

    /** @test */
    public function update_room_scene_supported_by_only_some_members_returns_skips()
    {
        $room = $this->createRoom([
            'name' => 'Mixed Room',
            'state' => false,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 50,
            'temperature' => 2700,
            'local_node_id' => 'test-node-id',
        ]);

        // Three full_colour bulbs — Ocean (scene 1) is supported.
        $fc1 = $this->createBulb([
            'mac' => 'aa:bb:cc:dd:ee:10',
            'ip' => '192.168.1.10',
            'name' => 'FC Bulb 1',
            'state' => false,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 75,
            'temperature' => 2700,
            'local_node_id' => 'other-node',
            'room_id' => (string) $room->id,
            'capability_class' => 'full_colour',
        ]);
        $fc2 = $this->createBulb([
            'mac' => 'aa:bb:cc:dd:ee:11',
            'ip' => '192.168.1.11',
            'name' => 'FC Bulb 2',
            'state' => false,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 75,
            'temperature' => 2700,
            'local_node_id' => 'other-node',
            'room_id' => (string) $room->id,
            'capability_class' => 'full_colour',
        ]);
        $fc3 = $this->createBulb([
            'mac' => 'aa:bb:cc:dd:ee:12',
            'ip' => '192.168.1.12',
            'name' => 'FC Bulb 3',
            'state' => false,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 75,
            'temperature' => 2700,
            'local_node_id' => 'other-node',
            'room_id' => (string) $room->id,
            'capability_class' => 'full_colour',
        ]);

        // Two tunable_white bulbs — Ocean (scene 1) is NOT supported.
        $tw1 = $this->createBulb([
            'mac' => 'aa:bb:cc:dd:ee:20',
            'ip' => '192.168.1.20',
            'name' => 'TW Bulb 1',
            'state' => false,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'dimming' => 75,
            'temperature' => 3000,
            'local_node_id' => 'other-node',
            'room_id' => (string) $room->id,
            'capability_class' => 'tunable_white',
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);
        $tw2 = $this->createBulb([
            'mac' => 'aa:bb:cc:dd:ee:21',
            'ip' => '192.168.1.21',
            'name' => 'TW Bulb 2',
            'state' => false,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'dimming' => 75,
            'temperature' => 3000,
            'local_node_id' => 'other-node',
            'room_id' => (string) $room->id,
            'capability_class' => 'tunable_white',
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'active_mode' => 'scene',
            'scene_id' => 1, // Ocean — full_colour only
        ]);

        $result = $controller->update($request, (string) $room->id);

        // Response should be 200 (room-level scene is never rejected).
        $this->assertEquals('scene', $result->active_mode);
        $this->assertEquals(1, $result->scene_id);

        // capability_skips should name each unsupported member with field: 'scene_id'.
        $skips = $result->getAttribute('capability_skips');
        $this->assertIsArray($skips);
        $this->assertCount(2, $skips);

        // Both tunable_white bulbs should be in the skips.
        $skipBulbIds = array_column($skips, 'bulb_id');
        $this->assertContains((string) $tw1->id, $skipBulbIds);
        $this->assertContains((string) $tw2->id, $skipBulbIds);

        // Each skip should have field == 'scene_id'.
        foreach ($skips as $skip) {
            $this->assertEquals('scene_id', $skip['field']);
            $this->assertStringContainsString('Ocean', $skip['reason']);
        }

        // The room's own active_mode/scene_id are saved regardless of member support.
        $room->refresh();
        $this->assertEquals('scene', $room->active_mode);
        $this->assertEquals(1, $room->scene_id);
    }
}
