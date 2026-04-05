<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
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
        $app['config']->set('clarion.node_id', 'test-node-id');
    }

    private function makeBulbMock(array $attrs = []): Bulb
    {
        $defaults = [
            'state' => true,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 75,
            'temperature' => 2700,
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

        return $bulb;
    }

    private function makeRoomMock(array $attrs = [], $bulbs = null): Room
    {
        $defaults = [
            'state' => true,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 50,
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

        return $room;
    }

    /** @test */
    public function update_preserves_room_dimming_when_omitted()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        $room = $this->makeRoomMock(['dimming' => 50], collect([]));
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, ['red' => 100]);

        $this->assertEquals(50, $result->dimming);
    }

    /** @test */
    public function update_preserves_bulb_brightness_when_dimming_omitted()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();

        $bulb = $this->makeBulbMock(['dimming' => 75]);
        $bulb->expects($this->once())->method('save');

        $room = $this->makeRoomMock(['dimming' => 75], collect([$bulb]));
        $room->expects($this->once())->method('save');

        $result = $service->updateRoomState($room, ['red' => 100]);

        $this->assertEquals(75, $bulb->dimming);
    }
}
