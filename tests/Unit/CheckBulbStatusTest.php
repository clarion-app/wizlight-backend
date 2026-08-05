<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Jobs\CheckBulbStatus;
use ClarionApp\WizlightBackend\Transport\FakeUdpTransport;
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

    private function makeTransport(?array $datagram = null): FakeUdpTransport
    {
        $fake = new FakeUdpTransport();

        return $datagram === null
            ? $fake->willRespondWithNothing()
            : $fake->willRespond($datagram);
    }

    /** @test */
    public function handle_returns_null_when_bulb_not_found()
    {
        $transport = $this->makeTransport();
        $job = new CheckBulbStatus('non-existent-uuid', $transport);
        $result = $job->handle();

        $this->assertNull($result);
    }

    /** @test */
    public function handle_uses_two_second_timeout()
    {
        $transport = new FakeUdpTransport();

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

        $this->assertEquals([2.0], $transport->receiveTimeouts(), 'CheckBulbStatus should use a 2-second timeout');
    }

    /** @test */
    public function handle_updates_signal_only_when_other_attributes_match()
    {
        $transport = $this->makeTransport([
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
        $transport = $this->makeTransport([
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
        $transport = $this->makeTransport([
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

    // ------------------------------------------------------------------
    // Phase 2 (US1): sceneId payload retention and no-mode-signal case
    // ------------------------------------------------------------------

    /** @test */
    public function handle_sceneId_payload_leaves_stored_rgb_unchanged()
    {
        // When a bulb is playing a scene, the pilot_state carries sceneId > 0
        // but no r/g/b values (or r/g/b = 0). CheckBulbStatus should NOT zero
        // out the stored RGB columns — it should leave them as-is.
        $transport = $this->makeTransport([
                'result' => [
                    'mac' => 'AA:BB:CC:DD:EE:10',
                    'state' => true,
                    'dimming' => 80,
                    'sceneId' => 1,
                    'r' => 0,
                    'g' => 0,
                    'b' => 0,
                    'rssi' => -45,
                ],
                'from' => '192.168.1.10',
            ]);

        $bulb = Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:10',
            'ip' => '192.168.1.10',
            'name' => 'Scene Bulb',
            'state' => true,
            'dimming' => 80,
            'red' => 255,
            'green' => 120,
            'blue' => 0,
            'signal' => -55,
            'scene_id' => 1,
            'active_mode' => 'scene',
        ]);

        Event::fake();
        $job = new CheckBulbStatus((string) $bulb->id, $transport);
        $job->handle();

        $bulb->refresh();
        $this->assertEquals(255, $bulb->red, 'Stored red should NOT be zeroed when scene is playing');
        $this->assertEquals(120, $bulb->green, 'Stored green should NOT be zeroed when scene is playing');
        $this->assertEquals(0, $bulb->blue, 'Stored blue should NOT be zeroed when scene is playing');
        $this->assertEquals(1, $bulb->scene_id, 'scene_id should be written');
        $this->assertEquals('scene', $bulb->active_mode, 'active_mode should be set to scene');
    }

    /** @test */
    public function handle_writes_scene_id_and_active_mode_on_scene_payload()
    {
        $transport = $this->makeTransport([
                'result' => [
                    'mac' => 'AA:BB:CC:DD:EE:11',
                    'state' => true,
                    'dimming' => 60,
                    'sceneId' => 9,
                    'rssi' => -50,
                ],
                'from' => '192.168.1.11',
            ]);

        $bulb = Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:11',
            'ip' => '192.168.1.11',
            'name' => 'Scene Bulb 2',
            'state' => true,
            'dimming' => 60,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -50,
            'scene_id' => null,
            'active_mode' => null,
        ]);

        Event::fake();
        $job = new CheckBulbStatus((string) $bulb->id, $transport);
        $job->handle();

        $bulb->refresh();
        $this->assertEquals(9, $bulb->scene_id, 'scene_id should be written from payload');
        $this->assertEquals('scene', $bulb->active_mode, 'active_mode should be set to scene');
    }

    /** @test */
    public function handle_payload_with_no_mode_signal_leaves_active_mode_untouched()
    {
        // Payload with only state and dimming — no colour, temp, scene, or
        // white channel signal. active_mode should remain unchanged.
        $transport = $this->makeTransport([
                'result' => [
                    'mac' => 'AA:BB:CC:DD:EE:12',
                    'state' => true,
                    'dimming' => 75,
                    'rssi' => -52,
                ],
                'from' => '192.168.1.12',
            ]);

        $bulb = Bulb::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'local_node_id' => (string) \Illuminate\Support\Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:12',
            'ip' => '192.168.1.12',
            'name' => 'No Mode Signal Bulb',
            'state' => true,
            'dimming' => 50,
            'red' => 100,
            'green' => 100,
            'blue' => 100,
            'signal' => -52,
            'scene_id' => null,
            'active_mode' => 'rgb',
        ]);

        Event::fake();
        $job = new CheckBulbStatus((string) $bulb->id, $transport);
        $job->handle();

        $bulb->refresh();
        $this->assertEquals('rgb', $bulb->active_mode, 'active_mode should remain unchanged when no mode signal');
    }
}
