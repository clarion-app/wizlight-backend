<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Jobs\CheckBulbStatus;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use Illuminate\Support\Facades\Event;

class CheckBulbStatusTest extends TestCase
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

    private function makeMockTransport(?array $receiveResponse = null): UdpTransport
    {
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('receive')->willReturn($receiveResponse);
        $mock->method('send');
        $mock->method('close');
        return $mock;
    }

    /** @test */
    public function handle_returns_null_when_bulb_not_found()
    {
        $transport = $this->makeMockTransport();
        $job = new CheckBulbStatus('non-existent-uuid', $transport);
        $result = $job->handle();

        $this->assertNull($result);
    }

    /** @test */
    public function handle_uses_two_second_timeout()
    {
        $timeoutReceived = null;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('receive')->willReturnCallback(function ($timeout) use (&$timeoutReceived) {
            $timeoutReceived = $timeout;
            return null;
        });
        $transport->method('send');
        $transport->method('close');

        $bulb = Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:01',
            'ip' => '192.168.1.10',
            'name' => 'Test Bulb',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -50,
        ]);

        $job = new CheckBulbStatus((string) $bulb->id, $transport);
        $job->handle();

        $this->assertEquals(2.0, $timeoutReceived, 'CheckBulbStatus should use a 2-second timeout');
    }

    /** @test */
    public function handle_updates_signal_only_when_other_attributes_match()
    {
        $transport = $this->makeMockTransport([
            [
                'result' => [
                    'mac' => 'AA:BB:CC:DD:EE:02',
                    'state' => true,
                    'dimming' => 80,
                    'r' => 100,
                    'g' => 150,
                    'b' => 200,
                    'rssi' => -40,
                ],
                'from' => '192.168.1.20',
            ],
        ]);

        $bulb = Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:02',
            'ip' => '192.168.1.20',
            'name' => 'Test Bulb',
            'state' => true,
            'dimming' => 80,
            'red' => 100,
            'green' => 150,
            'blue' => 200,
            'signal' => -60,
        ]);

        Event::fake();
        $job = new CheckBulbStatus((string) $bulb->id, $transport);
        $job->handle();

        $bulb->refresh();
        $this->assertEquals(-40, $bulb->signal, 'Signal should be updated even when other attributes match');
        Event::assertDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function handle_skips_update_when_all_attributes_match()
    {
        $transport = $this->makeMockTransport([
            [
                'result' => [
                    'mac' => 'AA:BB:CC:DD:EE:03',
                    'state' => false,
                    'dimming' => 50,
                    'r' => 255,
                    'g' => 200,
                    'b' => 100,
                    'rssi' => -55,
                ],
                'from' => '192.168.1.30',
            ],
        ]);

        $bulb = Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:03',
            'ip' => '192.168.1.30',
            'name' => 'Test Bulb',
            'state' => false,
            'dimming' => 50,
            'red' => 255,
            'green' => 200,
            'blue' => 100,
            'signal' => -55,
        ]);

        $originalUpdatedAt = $bulb->updated_at;
        sleep(1);

        $job = new CheckBulbStatus((string) $bulb->id, $transport);
        $job->handle();

        $bulb->refresh();
        $this->assertEquals($originalUpdatedAt, $bulb->updated_at, 'Bulb should not be updated when all attributes match');
    }

    /** @test */
    public function handle_corrects_db_to_match_physical_state()
    {
        $transport = $this->makeMockTransport([
            [
                'result' => [
                    'mac' => 'AA:BB:CC:DD:EE:04',
                    'state' => false,
                    'dimming' => 30,
                    'r' => 0,
                    'g' => 0,
                    'b' => 0,
                    'rssi' => -70,
                ],
                'from' => '192.168.1.40',
            ],
        ]);

        $bulb = Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:04',
            'ip' => '192.168.1.40',
            'name' => 'Test Bulb',
            'state' => true,
            'dimming' => 100,
            'red' => 255,
            'green' => 255,
            'blue' => 255,
            'signal' => -50,
        ]);

        $job = new CheckBulbStatus((string) $bulb->id, $transport);
        $job->handle();

        $bulb->refresh();
        $this->assertFalse((bool) $bulb->state, 'DB state should be corrected to match physical state');
        $this->assertEquals(30, $bulb->dimming, 'DB dimming should be corrected');
        $this->assertEquals(0, $bulb->red, 'DB red should be corrected');
        $this->assertEquals(0, $bulb->green, 'DB green should be corrected');
        $this->assertEquals(0, $bulb->blue, 'DB blue should be corrected');
        $this->assertEquals(-70, $bulb->signal, 'DB signal should be corrected');
    }
}
