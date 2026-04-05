<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
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

        // Seed the mock's internal storage
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
    public function updateRoomState_updates_room_and_child_bulbs()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        $bulb1 = $this->makeBulbMock(['state' => false]);
        $bulb1->expects($this->once())->method('save');

        $room = $this->makeRoomMock(['state' => false], collect([$bulb1]));
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, ['state' => true]);
        $this->assertTrue($result->state);
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
