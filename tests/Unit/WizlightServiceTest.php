<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use ClarionApp\WizlightBackend\Mode\ActiveMode;
use ClarionApp\WizlightBackend\Capability\CapabilityClass;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

class WizlightServiceTest extends TestCase
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
    }

    private function makeBulbMock(array $attrs = []): Bulb
    {
        $defaults = [
            'id' => 'bulb-uuid-1',
            'state' => false,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'dimming' => 100,
            'temperature' => 2700,
            'name' => 'Test Bulb',
            'room_id' => null,
            'ip' => '192.168.1.10',
            'local_node_id' => 'other-node',
        ];
        $data = array_merge($defaults, $attrs);

        $bulb = $this->getMockBuilder(Bulb::class)
            ->onlyMethods(['save', 'getAttribute', 'setAttribute'])
            ->getMock();

        $storage = $data;

        $bulb->method('getAttribute')->willReturnCallback(function ($key) use (&$storage) {
            return $storage[$key] ?? null;
        });

        $bulb->method('setAttribute')->willReturnCallback(function ($key, $value) use (&$storage, $bulb) {
            $storage[$key] = $value;
            return $bulb;
        });

        foreach ($data as $k => $v) {
            $storage[$k] = $v;
        }

        return $bulb;
    }

    private function makeRoomMock(array $attrs = [], $bulbs = null): Room
    {
        $defaults = [
            'state' => false,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'dimming' => 100,
            'temperature' => 2700,
            'name' => 'Test Room',
            'local_node_id' => 'other-node',
        ];
        $data = array_merge($defaults, $attrs);
        $bulbCollection = $bulbs ?? collect([]);

        $room = $this->getMockBuilder(Room::class)
            ->onlyMethods(['save', 'getAttribute', 'setAttribute'])
            ->getMock();

        $storage = $data;

        $room->method('getAttribute')->willReturnCallback(function ($key) use (&$storage, $bulbCollection) {
            if ($key === 'bulbs') return $bulbCollection;
            return $storage[$key] ?? null;
        });

        $room->method('setAttribute')->willReturnCallback(function ($key, $value) use (&$storage, $room) {
            $storage[$key] = $value;
            return $room;
        });

        foreach ($data as $k => $v) {
            $storage[$k] = $v;
        }

        return $room;
    }

    /** @test */
    public function updateBulbState_reconciles_changed_fields()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock(['state' => false, 'red' => 255, 'dimming' => 50]);
        $bulb->expects($this->once())->method('save');

        $result = $service->updateBulbState($bulb, ['state' => true, 'red' => 100]);

        $this->assertTrue($result->state);
        $this->assertEquals(100, $result->red);
    }

    /** @test */
    public function updateBulbState_preserves_dimming_when_omitted()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock(['dimming' => 50, 'red' => 255]);
        $bulb->expects($this->once())->method('save');

        $result = $service->updateBulbState($bulb, ['red' => 100]);

        $this->assertEquals(50, $result->dimming);
    }

    /** @test */
    public function updateBulbState_dispatches_send_bulb_command_for_local_node()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock([
            'state' => false,
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.10',
            'id' => 'bulb-uuid-dispatch',
        ]);
        $bulb->expects($this->once())->method('save');

        $service->updateBulbState($bulb, ['state' => true]);

        Bus::assertDispatchedSync(SendBulbCommand::class, function ($job) use ($bulb) {
            return $job->ip === '192.168.1.10' && $job->bulbId === 'bulb-uuid-dispatch';
        });
    }

    /** @test */
    public function updateBulbState_skips_dispatch_for_remote_node()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock([
            'state' => false,
            'local_node_id' => 'other-node',
        ]);
        $bulb->expects($this->once())->method('save');

        $service->updateBulbState($bulb, ['state' => true]);

        Bus::assertNotDispatchedSync(SendBulbCommand::class);
    }

    /** @test */
    public function updateBulbState_dispatches_no_command_when_no_state_change()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock([
            'state' => false,
            'local_node_id' => 'test-node-id',
            'name' => 'Test Bulb',
        ]);

        $result = $service->updateBulbState($bulb, ['state' => false]);

        Bus::assertNotDispatchedSync(SendBulbCommand::class);
    }

    /** @test */
    public function updateBulbState_fires_bulb_status_event_on_change()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock(['state' => false, 'local_node_id' => 'test-node-id']);
        $bulb->expects($this->once())->method('save');

        $service->updateBulbState($bulb, ['state' => true]);

        Event::assertDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function updateRoomState_updates_room_and_child_bulbs()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        $bulb1 = $this->makeBulbMock([
            'state' => false,
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.10',
            'id' => 'bulb-room-1',
        ]);
        $bulb1->expects($this->once())->method('save');

        $room = $this->makeRoomMock(['state' => false], collect([$bulb1]));
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, ['state' => true]);
        $roomResult = is_array($result) ? $result['room'] : $result;
        $this->assertTrue($roomResult->state);
    }

    /** @test */
    public function updateRoomState_dispatches_send_bulb_command_per_local_bulb()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        $bulb1 = $this->makeBulbMock([
            'state' => false,
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.10',
            'id' => 'bulb-room-local',
        ]);
        $bulb1->expects($this->once())->method('save');

        $room = $this->makeRoomMock(['state' => false], collect([$bulb1]));
        $room->expects($this->once())->method('save');

        $service->updateRoomState($room, ['state' => true]);

        Bus::assertDispatchedSync(SendBulbCommand::class, function ($job) {
            return $job->bulbId === 'bulb-room-local';
        });
    }

    /** @test */
    public function updateRoomState_skips_dispatch_for_remote_bulbs()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        $bulb1 = $this->makeBulbMock([
            'state' => false,
            'local_node_id' => 'other-node',
        ]);
        $bulb1->expects($this->once())->method('save');

        $room = $this->makeRoomMock(['state' => false], collect([$bulb1]));
        $room->expects($this->once())->method('save');

        $service->updateRoomState($room, ['state' => true]);

        Bus::assertNotDispatchedSync(SendBulbCommand::class);
    }

    /** @test */
    public function updateRoomState_preserves_dimming_when_omitted()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        $room = $this->makeRoomMock(['dimming' => 50, 'red' => 255], collect([]));
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, ['red' => 100]);
        $roomResult = is_array($result) ? $result['room'] : $result;
        $this->assertEquals(50, $roomResult->dimming);
    }

    /** @test */
    public function updateRoomState_handles_empty_bulb_list_gracefully()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        $room = $this->makeRoomMock(['state' => false], collect([]));
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, ['state' => true]);
        $roomResult = is_array($result) ? $result['room'] : $result;
        $this->assertTrue($roomResult->state);
    }

    /** @test */
    public function buildCommand_uses_integer_values_for_rgb()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 255;
        $bulb->green = 128;
        $bulb->blue = 0;
        $bulb->dimming = 75;
        $bulb->temperature = 0;
        $bulb->state = true;

        $command = $service->buildCommand($bulb);

        $this->assertEquals(255, $command->params->r);
        $this->assertEquals(128, $command->params->g);
        $this->assertEquals(0, $command->params->b);
        $this->assertEquals(75, $command->params->dimming);
        $this->assertIsInt($command->params->r);
        $this->assertIsInt($command->params->dimming);
    }

    /** @test */
    public function buildCommand_uses_integer_values_for_temperature()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 0;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 50;
        $bulb->temperature = 4000;
        $bulb->state = true;

        $command = $service->buildCommand($bulb);

        // With mode-based approach, zero RGB + non-zero temp resolves to
        // WARMTH mode, so only temp is emitted (modes are mutually exclusive).
        $this->assertEquals(50, $command->params->dimming);
        $this->assertEquals(4000, $command->params->temp);
        $this->assertIsInt($command->params->temp);
        $this->assertIsInt($command->params->dimming);
        $this->assertObjectHasProperty('temp', $command->params);
        $this->assertObjectNotHasProperty('r', $command->params);
        $this->assertObjectNotHasProperty('g', $command->params);
        $this->assertObjectNotHasProperty('b', $command->params);
    }

    /** @test */
    public function buildCommand_uses_model_dimming_not_request_dimming()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 255;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 42;
        $bulb->temperature = 0;
        $bulb->state = true;

        $command = $service->buildCommand($bulb);

        $this->assertEquals(42, $command->params->dimming);
    }

    // ------------------------------------------------------------------
    // Phase 4 (US2): Room capability filtering
    // ------------------------------------------------------------------

    /** @test */
    public function updateRoomState_returns_capability_skips_when_fields_filtered()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        // Dim-only bulb: cannot accept colour or temperature.
        $dimBulb = $this->makeBulbMock([
            'state' => false,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'dimming' => 100,
            'temperature' => 2700,
            'id' => 'bulb-dim-room',
            'local_node_id' => 'other-node',
            'capability_class' => 'dim_only',
        ]);
        $dimBulb->expects($this->once())->method('save');

        // Full-colour bulb: accepts everything.
        $fullBulb = $this->makeBulbMock([
            'state' => false,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'dimming' => 100,
            'temperature' => 2700,
            'id' => 'bulb-full-room',
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.20',
            'capability_class' => 'full_colour',
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);
        $fullBulb->expects($this->once())->method('save');

        $room = $this->makeRoomMock(
            ['state' => false, 'red' => 255, 'green' => 255, 'blue' => 255, 'dimming' => 100, 'temperature' => 2700],
            collect([$dimBulb, $fullBulb])
        );
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, [
            'state' => true,
            'red' => 200,
            'green' => 100,
            'blue' => 50,
            'temperature' => 4000,
            'dimming' => 75,
        ]);

        // The response should include capability_skips.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('capability_skips', $result);
        $skips = $result['capability_skips'];
        // Dim-only bulb should have skips for red, green, blue, temperature.
        $dimSkips = array_filter($skips, fn ($s) => $s['bulb_id'] === 'bulb-dim-room');
        $this->assertCount(4, $dimSkips);
        // Full-colour bulb should have zero skips.
        $fullSkips = array_filter($skips, fn ($s) => $s['bulb_id'] === 'bulb-full-room');
        $this->assertCount(0, $fullSkips);
    }

    /** @test */
    public function updateRoomState_skips_bulb_with_zero_applicable_fields()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        // Dim-only bulb: a colour-only command has zero applicable fields.
        $dimBulb = $this->makeBulbMock([
            'state' => false,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'dimming' => 100,
            'temperature' => 2700,
            'id' => 'bulb-skip-room',
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.30',
            'capability_class' => 'dim_only',
        ]);
        // Because all fields are filtered out, save should NOT be called.
        $dimBulb->expects($this->never())->method('save');

        $room = $this->makeRoomMock(
            ['state' => false, 'red' => 255, 'green' => 255, 'blue' => 255, 'dimming' => 100, 'temperature' => 2700],
            collect([$dimBulb])
        );
        $room->expects($this->once())->method('save');

        $service->updateRoomState($room, [
            'red' => 200,
            'green' => 100,
            'blue' => 50,
        ]);

        // No command dispatched for the skipped bulb.
        Bus::assertNotDispatchedSync(SendBulbCommand::class);
        Event::assertNotDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function updateRoomState_preserves_room_aggregate_row_unchanged()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        // Dim-only bulb in room.
        $dimBulb = $this->makeBulbMock([
            'state' => false,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'dimming' => 100,
            'temperature' => 2700,
            'id' => 'bulb-agg-room',
            'local_node_id' => 'other-node',
            'capability_class' => 'dim_only',
        ]);

        $room = $this->makeRoomMock(
            ['state' => false, 'red' => 255, 'green' => 255, 'blue' => 255, 'dimming' => 100, 'temperature' => 2700],
            collect([$dimBulb])
        );
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, [
            'state' => true,
            'red' => 200,
            'green' => 100,
            'blue' => 50,
            'temperature' => 4000,
            'dimming' => 75,
        ]);

        // The room's aggregate row should still record the full requested state,
        // even though the dim-only bulb can't accept colour or temperature.
        $roomResult = is_array($result) ? $result['room'] : $result;
        $this->assertTrue($roomResult->state);
        $this->assertEquals(200, $roomResult->red);
        $this->assertEquals(100, $roomResult->green);
        $this->assertEquals(50, $roomResult->blue);
        $this->assertEquals(4000, $roomResult->temperature);
        $this->assertEquals(75, $roomResult->dimming);
    }

    // ------------------------------------------------------------------
    // Phase 2 (US1): buildCommand shape per mode, ratio, retention
    // ------------------------------------------------------------------

    /** @test */
    public function buildCommand_scene_animated_includes_sceneId_and_speed()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 0;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 50;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = 1;
        $bulb->scene_speed = 150;
        $bulb->active_mode = 'scene';

        $command = $service->buildCommand($bulb);

        $this->assertEquals(1, $command->params->sceneId);
        $this->assertEquals(150, $command->params->speed);
        $this->assertEquals(50, $command->params->dimming);
    }

    /** @test */
    public function buildCommand_scene_static_includes_sceneId_no_speed()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 0;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 50;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = 11;
        $bulb->scene_speed = null;
        $bulb->active_mode = 'scene';

        $command = $service->buildCommand($bulb);

        $this->assertEquals(11, $command->params->sceneId);
        $this->assertObjectNotHasProperty('speed', $command->params, 'Static scene should not include speed');
    }

    /** @test */
    public function buildCommand_rgb_includes_rgb_fields()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 255;
        $bulb->green = 128;
        $bulb->blue = 0;
        $bulb->dimming = 75;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = null;
        $bulb->scene_speed = null;
        $bulb->active_mode = 'rgb';

        $command = $service->buildCommand($bulb);

        $this->assertEquals(255, $command->params->r);
        $this->assertEquals(128, $command->params->g);
        $this->assertEquals(0, $command->params->b);
        $this->assertEquals(75, $command->params->dimming);
    }

    /** @test */
    public function buildCommand_warmth_includes_temp()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 0;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 50;
        $bulb->temperature = 4000;
        $bulb->state = true;
        $bulb->scene_id = null;
        $bulb->scene_speed = null;
        $bulb->active_mode = 'warmth';

        $command = $service->buildCommand($bulb);

        $this->assertEquals(4000, $command->params->temp);
        $this->assertEquals(50, $command->params->dimming);
    }

    /** @test */
    public function buildCommand_white_channels_includes_c_and_w()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 0;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 50;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = null;
        $bulb->scene_speed = null;
        $bulb->active_mode = 'white_channels';
        $bulb->white_warm = 200;
        $bulb->white_cool = 40;

        $command = $service->buildCommand($bulb);

        $this->assertEquals(200, $command->params->w);
        $this->assertEquals(40, $command->params->c);
        $this->assertEquals(50, $command->params->dimming);
    }

    /** @test */
    public function buildCommand_ratio_present_on_dual_head()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 255;
        $bulb->green = 128;
        $bulb->blue = 0;
        $bulb->dimming = 75;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = null;
        $bulb->scene_speed = null;
        $bulb->active_mode = 'rgb';
        $bulb->model = 'ESP01_DHRGB_03';
        // The stored capability fact is what the builder reads; the module name
        // is only how discovery derived it.
        $bulb->dual_head = true;
        $bulb->head_ratio = 50;

        $command = $service->buildCommand($bulb);

        $this->assertObjectHasProperty('ratio', $command->params, 'Dual-head device should include ratio');
        $this->assertEquals(50, $command->params->ratio);
    }

    /** @test */
    public function buildCommand_ratio_absent_on_non_dual_head()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 255;
        $bulb->green = 128;
        $bulb->blue = 0;
        $bulb->dimming = 75;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = null;
        $bulb->scene_speed = null;
        $bulb->active_mode = 'rgb';
        $bulb->model = 'ESP01_SHRGB_03';

        $command = $service->buildCommand($bulb);

        $this->assertObjectNotHasProperty('ratio', $command->params, 'Non-dual-head device should not include ratio');
    }

    /** @test */
    public function buildCommand_mode_switch_leaves_superseded_columns_unwritten()
    {
        // Retention: switching from warmth to rgb should not zero out temperature.
        // The bulb model carries the old temperature value.
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 255;
        $bulb->green = 128;
        $bulb->blue = 0;
        $bulb->dimming = 75;
        $bulb->temperature = 4000;
        $bulb->state = true;
        $bulb->scene_id = null;
        $bulb->scene_speed = null;
        $bulb->active_mode = 'rgb';
        $bulb->model = 'ESP01_SHRGB_03';

        $command = $service->buildCommand($bulb);

        // RGB command should not include temp parameter.
        $this->assertObjectNotHasProperty('temp', $command->params, 'RGB command should not include temp');
    }

    /** @test */
    public function updateBulbState_writes_active_mode_column()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock([
            'state' => false,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'active_mode' => 'rgb',
        ]);
        $bulb->expects($this->once())->method('save');

        $result = $service->updateBulbState($bulb, [
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'temperature' => 3000,
            'active_mode' => 'warmth',
        ]);

        $this->assertEquals('warmth', $result->active_mode);
    }

    // ------------------------------------------------------------------
    // Phase 5 (US2): scene_speed copied onto model and reflected in buildCommand
    // ------------------------------------------------------------------

    /** @test */
    public function updateBulbState_copies_scene_speed_onto_model_for_animated_scene()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock([
            'state' => true,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'active_mode' => 'rgb',
            'scene_id' => null,
            'scene_speed' => null,
        ]);
        $bulb->expects($this->once())->method('save');

        $result = $service->updateBulbState($bulb, [
            'active_mode' => 'scene',
            'scene_id' => 1,
            'scene_speed' => 150,
        ]);

        $this->assertEquals(1, $result->scene_id);
        $this->assertEquals(150, $result->scene_speed);
        $this->assertEquals('scene', $result->active_mode);
    }

    /** @test */
    public function buildCommand_reflects_scene_speed_in_speed_param_for_animated_scene()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 0;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 80;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = 1;
        $bulb->scene_speed = 150;
        $bulb->active_mode = 'scene';

        $command = $service->buildCommand($bulb);

        $this->assertEquals(1, $command->params->sceneId);
        $this->assertEquals(150, $command->params->speed);
        $this->assertEquals(80, $command->params->dimming);
    }

    /** @test */
    public function buildCommand_omits_speed_for_static_scene_even_when_scene_speed_stored()
    {
        $service = new WizlightService();

        // Warm white (11) is static. Even if scene_speed is stored, speed
        // should not appear in the command.
        $bulb = new \stdClass();
        $bulb->red = 0;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 60;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = 11;
        $bulb->scene_speed = 150;
        $bulb->active_mode = 'scene';

        $command = $service->buildCommand($bulb);

        $this->assertEquals(11, $command->params->sceneId);
        $this->assertObjectNotHasProperty('speed', $command->params, 'Static scene should not include speed even when scene_speed is stored');
    }

    /** @test */
    public function updateBulbState_leaves_scene_speed_unchanged_when_scene_is_static()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock([
            'state' => true,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'active_mode' => 'rgb',
            'scene_id' => 11,
            'scene_speed' => 100,
        ]);
        $bulb->expects($this->once())->method('save');

        // Switching to Warm white (11) — static — should not change scene_speed
        // even if a new value is sent (the validator should reject it, but here
        // we test the service layer: scene_speed is copied when present and changed).
        $result = $service->updateBulbState($bulb, [
            'active_mode' => 'scene',
            'scene_id' => 11,
        ]);

        // scene_speed should remain at 100 (unchanged, because it was not in the request).
        $this->assertEquals(100, $result->scene_speed);
        $this->assertEquals(11, $result->scene_id);
    }

    // ------------------------------------------------------------------
    // Phase 6 (US3): Room scene fan-out with mixed-capability members
    // ------------------------------------------------------------------

    /** @test */
    public function updateRoomState_scene_fan_out_skips_member_lacking_scene_support()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        // Full-colour bulb — Ocean (scene 1) is supported.
        $fcBulb = $this->makeBulbMock([
            'state' => true,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 80,
            'temperature' => 2700,
            'id' => 'bulb-fc-scene-room',
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.40',
            'capability_class' => 'full_colour',
            'scene_id' => null,
            'scene_speed' => null,
            'active_mode' => 'rgb',
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);
        $fcBulb->expects($this->once())->method('save');

        // Tunable-white bulb — Ocean (scene 1) is NOT supported.
        // Its active_mode is already 'scene' so the active_mode field won't
        // trigger a change (value matches stored). Only scene_id is in the
        // request beyond active_mode, and it will be filtered out by
        // filterForRoom().  The bulb should therefore receive no save, no
        // dispatch, no event — "left entirely alone" per the contract.
        $twBulb = $this->makeBulbMock([
            'state' => true,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'dimming' => 60,
            'temperature' => 3500,
            'id' => 'bulb-tw-scene-room',
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.41',
            'capability_class' => 'tunable_white',
            'scene_id' => null,
            'scene_speed' => null,
            'active_mode' => 'scene',
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);
        // Because active_mode == 'scene' (same as request) and scene_id is
        // filtered, only always-pass fields remain (none changing) — bulb
        // should be left entirely alone.
        $twBulb->expects($this->never())->method('save');

        $room = $this->makeRoomMock(
            [
                'state' => false,
                'red' => 255,
                'green' => 0,
                'blue' => 0,
                'dimming' => 50,
                'temperature' => 2700,
                'active_mode' => null,
                'scene_id' => null,
            ],
            collect([$fcBulb, $twBulb])
        );
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, [
            'active_mode' => 'scene',
            'scene_id' => 1, // Ocean — full_colour only
        ]);

        // Room aggregate should have scene saved regardless of member support.
        $roomResult = is_array($result) ? $result['room'] : $result;
        $this->assertEquals('scene', $roomResult->active_mode);
        $this->assertEquals(1, $roomResult->scene_id);

        // The full-colour bulb should have scene_id applied.
        $this->assertEquals(1, $fcBulb->scene_id);

        // The tunable-white bulb should NOT have scene_id written to it.
        $this->assertNull($twBulb->scene_id, 'Tunable-white bulb should not have scene_id set');

        // capability_skips should record the tunable-white bulb's skip.
        $skips = $result['capability_skips'];
        $this->assertCount(1, $skips);
        $this->assertEquals('bulb-tw-scene-room', $skips[0]['bulb_id']);
        $this->assertEquals('scene_id', $skips[0]['field']);
    }

    // ------------------------------------------------------------------
    // US4: white_channels command shape and retention of superseded values
    // ------------------------------------------------------------------

    /** @test */
    public function us4_build_command_white_channels_emits_c_w_omits_rgb_temp_scene()
    {
        $service = new WizlightService();

        $bulb = new \stdClass();
        $bulb->red = 0;
        $bulb->green = 0;
        $bulb->blue = 0;
        $bulb->dimming = 80;
        $bulb->temperature = 0;
        $bulb->state = true;
        $bulb->scene_id = null;
        $bulb->scene_speed = null;
        $bulb->active_mode = ActiveMode::WHITE_CHANNELS;
        $bulb->white_warm = 200;
        $bulb->white_cool = 40;

        $command = $service->buildCommand($bulb);

        // white_channels mode emits 'c' (white_warm) and 'w' (white_cool).
        $this->assertTrue(property_exists($command->params, 'w'));
        $this->assertSame(200, $command->params->w);
        $this->assertTrue(property_exists($command->params, 'c'));
        $this->assertSame(40, $command->params->c);
        // Must NOT emit colour fields, temperature, or sceneId.
        $this->assertFalse(property_exists($command->params, 'r'));
        $this->assertFalse(property_exists($command->params, 'g'));
        $this->assertFalse(property_exists($command->params, 'b'));
        $this->assertFalse(property_exists($command->params, 'temp'));
        $this->assertFalse(property_exists($command->params, 'sceneId'));
    }

    /** @test */
    public function us4_update_bulb_state_switching_to_white_channels_retains_colour_and_warmth()
    {
        // Bulb mock with existing colour and warmth values.
        $bulb = $this->makeBulbMock([
            'id' => 'bulb-retain-white-channels',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'red' => 255,
            'green' => 128,
            'blue' => 64,
            'temperature' => 3000,
            'active_mode' => ActiveMode::RGB,
            'white_warm' => null,
            'white_cool' => null,
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.10',
        ]);

        $service = new WizlightService();
        $service->updateBulbState($bulb, [
            'active_mode' => ActiveMode::WHITE_CHANNELS,
            'white_warm' => 200,
            'white_cool' => 40,
            'dimming' => 80,
        ]);

        // Switching to white_channels mode should update white_warm, white_cool, active_mode.
        $this->assertSame(ActiveMode::WHITE_CHANNELS, $bulb->getAttribute('active_mode'));
        $this->assertSame(200, $bulb->getAttribute('white_warm'));
        $this->assertSame(40, $bulb->getAttribute('white_cool'));
        // Previous colour and warmth values should be retained (not cleared).
        $this->assertSame(255, $bulb->getAttribute('red'));
        $this->assertSame(128, $bulb->getAttribute('green'));
        $this->assertSame(64, $bulb->getAttribute('blue'));
        $this->assertSame(3000, $bulb->getAttribute('temperature'));
    }

    // ------------------------------------------------------------------
    // US5: head balance rides on every command, orthogonal to the mode
    // ------------------------------------------------------------------

    /**
     * A dual-head bulb carrying a stored balance, with the mode's own fields
     * populated. `model` is deliberately absent: dual-head is a stored
     * capability fact derived once at discovery, and the command path must read
     * it rather than re-derive it from the module name.
     */
    private function dualHeadBulbInMode(string $mode): \stdClass
    {
        $bulb = new \stdClass();
        $bulb->state = true;
        $bulb->dimming = 80;
        $bulb->red = 255;
        $bulb->green = 128;
        $bulb->blue = 0;
        $bulb->temperature = 3000;
        $bulb->white_warm = 200;
        $bulb->white_cool = 40;
        $bulb->scene_id = 1;
        $bulb->scene_speed = 140;
        $bulb->active_mode = $mode;
        $bulb->dual_head = true;
        $bulb->head_ratio = 75;

        return $bulb;
    }

    /** @test */
    public function us5_ratio_present_in_scene_mode_on_a_dual_head_bulb()
    {
        $command = (new WizlightService())->buildCommand($this->dualHeadBulbInMode(ActiveMode::SCENE));

        $this->assertObjectHasProperty('ratio', $command->params, 'Scene mode must still carry the head balance');
        $this->assertSame(75, $command->params->ratio);
        $this->assertObjectHasProperty('sceneId', $command->params, 'The mode still owns its own parameters');
    }

    /** @test */
    public function us5_ratio_present_in_rgb_mode_on_a_dual_head_bulb()
    {
        $command = (new WizlightService())->buildCommand($this->dualHeadBulbInMode(ActiveMode::RGB));

        $this->assertObjectHasProperty('ratio', $command->params, 'RGB mode must still carry the head balance');
        $this->assertSame(75, $command->params->ratio);
        $this->assertObjectHasProperty('r', $command->params);
    }

    /** @test */
    public function us5_ratio_present_in_warmth_mode_on_a_dual_head_bulb()
    {
        $command = (new WizlightService())->buildCommand($this->dualHeadBulbInMode(ActiveMode::WARMTH));

        $this->assertObjectHasProperty('ratio', $command->params, 'Warmth mode must still carry the head balance');
        $this->assertSame(75, $command->params->ratio);
        $this->assertObjectHasProperty('temp', $command->params);
    }

    /** @test */
    public function us5_ratio_present_in_white_channels_mode_on_a_dual_head_bulb()
    {
        $command = (new WizlightService())->buildCommand($this->dualHeadBulbInMode(ActiveMode::WHITE_CHANNELS));

        $this->assertObjectHasProperty('ratio', $command->params, 'White-channel mode must still carry the head balance');
        $this->assertSame(75, $command->params->ratio);
        $this->assertObjectHasProperty('c', $command->params);
        $this->assertObjectHasProperty('w', $command->params);
    }

    /** @test */
    public function us5_ratio_absent_when_the_stored_dual_head_fact_is_false()
    {
        // The module name says DH, the stored capability fact says otherwise.
        // The stored fact governs — capability is never re-derived on the
        // command path, so a corrected flag takes effect without a re-probe.
        $bulb = $this->dualHeadBulbInMode(ActiveMode::RGB);
        $bulb->model = 'ESP01_DHRGB_03';
        $bulb->dual_head = false;

        $command = (new WizlightService())->buildCommand($bulb);

        $this->assertObjectNotHasProperty('ratio', $command->params, 'A single-head device takes no ratio');
    }

    /** @test */
    public function us5_ratio_absent_when_dual_head_was_never_probed()
    {
        $bulb = $this->dualHeadBulbInMode(ActiveMode::RGB);
        $bulb->model = 'ESP01_DHRGB_03';
        $bulb->dual_head = null;

        $command = (new WizlightService())->buildCommand($bulb);

        $this->assertObjectNotHasProperty('ratio', $command->params, 'An unprobed device reads as single-head');
    }

    /** @test */
    public function us5_ratio_absent_on_a_dual_head_bulb_with_no_stored_balance()
    {
        $bulb = $this->dualHeadBulbInMode(ActiveMode::RGB);
        $bulb->head_ratio = null;

        $command = (new WizlightService())->buildCommand($bulb);

        $this->assertObjectNotHasProperty('ratio', $command->params, 'No stored balance, nothing to send');
    }
}
