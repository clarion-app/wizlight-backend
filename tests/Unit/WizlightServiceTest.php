<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
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

        Bus::assertDispatched(SendBulbCommand::class, function ($job) use ($bulb) {
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

        Bus::assertNotDispatched(SendBulbCommand::class);
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

        Bus::assertNotDispatched(SendBulbCommand::class);
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
        $this->assertTrue($result->state);
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

        Bus::assertDispatched(SendBulbCommand::class, function ($job) {
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

        Bus::assertNotDispatched(SendBulbCommand::class);
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
        $this->assertEquals(50, $result->dimming);
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
        $this->assertTrue($result->state);
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

        $this->assertEquals(0, $command->params->r);
        $this->assertEquals(0, $command->params->g);
        $this->assertEquals(0, $command->params->b);
        $this->assertEquals(50, $command->params->dimming);
        $this->assertEquals(4000, $command->params->temp);
        $this->assertIsInt($command->params->temp);
        $this->assertIsInt($command->params->dimming);
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
}
