<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

class BulbControllerTest extends TestCase
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
            'dimming' => 50,
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

        return $bulb;
    }

    /** @test */
    public function update_preserves_dimming_when_omitted()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock(['dimming' => 50]);
        $bulb->expects($this->once())->method('save');

        $result = $service->updateBulbState($bulb, ['red' => 100, 'green' => 200]);

        $this->assertEquals(50, $result->dimming);
    }

    /** @test */
    public function update_sets_dimming_when_explicitly_provided()
    {
        Bus::fake();
        Event::fake();
        $service = new WizlightService();
        $bulb = $this->makeBulbMock(['dimming' => 50]);
        $bulb->expects($this->once())->method('save');

        $result = $service->updateBulbState($bulb, ['dimming' => 75]);

        $this->assertEquals(75, $result->dimming);
    }

    /** @test */
    public function update_validates_room_id_rejects_invalid()
    {
        $rules = [
            'room_id' => 'nullable|uuid|exists:wizlight_rooms,id',
        ];

        $this->assertStringContainsString('exists:wizlight_rooms,id', $rules['room_id']);
        $this->assertStringContainsString('uuid', $rules['room_id']);
        $this->assertStringContainsString('nullable', $rules['room_id']);
    }
}
