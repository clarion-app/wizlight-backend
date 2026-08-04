<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Jobs\BulbDiscovery;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\BulbLastSeen;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class BulbDiscoveryTest extends TestCase
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
        $app['config']->set('cache.stores.testing', [
            'driver' => 'array',
        ]);
        $app['config']->set('cache.default', 'testing');
        $app['config']->set('wizlight.broadcast_address', '255.255.255.255');
        $app['config']->set('wizlight.udp_wait_time', 30.0);
        $app['config']->set('wizlight.phone_mac', 'AAAAAAAAAAAA');
        $app['config']->set('wizlight.udp_port', 38899);
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
        $app['config']->set('clarion.node_id', 'test-node-001');
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/Migrations');
    }

    private function makeMockTransport(array $receiveResponses): UdpTransport
    {
        $index = 0;
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('receive')->willReturnCallback(function () use (&$index, &$receiveResponses) {
            $response = $receiveResponses[$index++] ?? [];
            return $response;
        });
        return $mock;
    }

    private function buildDiscoveryResponses(array $bulbs): array
    {
        $responses = [];
        $registrationResults = [];
        foreach ($bulbs as $b) {
            $registrationResults[] = [
                'result' => ['mac' => $b['mac']],
                'from' => $b['ip'],
            ];
        }
        $responses[] = $registrationResults;

        // New discover() only calls getPilot + getSystemConfig per bulb (no getUserConfig)
        foreach ($bulbs as $b) {
            $responses[] = $b['pilot_response'] ?? [];
            $responses[] = $b['sysconfig_response'] ?? [];
        }

        return $responses;
    }

    /** @test */
    public function discover_returns_pilot_state_and_system_config_data()
    {
        // New discover() calls: registration, then per bulb: getPilot + getSystemConfig (no getUserConfig)
        $responses = [
            // Registration: one bulb
            [
                ['result' => ['mac' => 'AA:BB:CC:DD:EE:01'], 'from' => '192.168.1.10'],
            ],
            // getPilot response
            [
                ['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'state' => true, 'dimming' => 80, 'r' => 200, 'g' => 100, 'b' => 50, 'rssi' => -45], 'from' => '192.168.1.10'],
            ],
            // getSystemConfig response
            [
                ['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'moduleName' => 'Wiz Connected Bulb A19'], 'from' => '192.168.1.10'],
            ],
        ];

        $transport = $this->makeMockTransport($responses);
        $wiz = new Wiz(transport: $transport);
        $results = $wiz->discover();

        $this->assertCount(1, $results);
        $this->assertEquals('AA:BB:CC:DD:EE:01', $results[0]['mac']);
        $this->assertEquals('192.168.1.10', $results[0]['ip']);
        $this->assertArrayHasKey('pilot_state', $results[0]);
        $this->assertArrayHasKey('system_config', $results[0]);
        $this->assertTrue($results[0]['pilot_state']['state']);
        $this->assertEquals(80, $results[0]['pilot_state']['dimming']);
        $this->assertEquals(200, $results[0]['pilot_state']['r']);
        $this->assertEquals('Wiz Connected Bulb A19', $results[0]['system_config']['moduleName']);
    }

    /** @test */
    public function discovery_creates_bulb_with_captured_state()
    {
        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:01',
                'ip' => '192.168.1.10',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'state' => true, 'dimming' => 75, 'r' => 180, 'g' => 120, 'b' => 60, 'rssi' => -50], 'from' => '192.168.1.10'],
                ],
                'sysconfig_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'moduleName' => 'Wiz A19'], 'from' => '192.168.1.10'],
                ],
            ],
        ];

        $responses = $this->buildDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);

        Event::fake();
        app()->instance(UdpTransport::class, $transport);

        $job = new BulbDiscovery();
        $job->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:01')->first();
        $this->assertNotNull($bulb, 'Bulb should be created');
        $this->assertEquals('192.168.1.10', $bulb->ip);
        $this->assertTrue((bool) $bulb->state, 'Bulb state should match captured pilot_state');
        $this->assertEquals(75, $bulb->dimming, 'Dimming should match captured pilot_state');
        $this->assertEquals(180, $bulb->red, 'Red should match captured pilot_state');
        $this->assertEquals(120, $bulb->green, 'Green should match captured pilot_state');
        $this->assertEquals(60, $bulb->blue, 'Blue should match captured pilot_state');
        $this->assertEquals(-50, $bulb->signal, 'Signal should match captured rssi');
        $this->assertEquals('Wiz A19', $bulb->model, 'Model should match captured system_config');

        Event::assertDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function discovery_creates_bulb_last_seen_record()
    {
        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:02',
                'ip' => '192.168.1.11',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:02', 'state' => false, 'dimming' => 100, 'r' => 0, 'g' => 0, 'b' => 0, 'rssi' => -60], 'from' => '192.168.1.11'],
                ],
                'sysconfig_response' => [],
            ],
        ];

        $responses = $this->buildDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        $job = new BulbDiscovery();
        $job->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:02')->first();
        $this->assertNotNull($bulb);

        $lastSeen = BulbLastSeen::where('bulb_id', $bulb->id)->first();
        $this->assertNotNull($lastSeen, 'BulbLastSeen record should be created');
    }

    /** @test */
    public function first_discovery_wins_ownership()
    {
        $existingNodeId = (string) \Illuminate\Support\Str::uuid();
        $existingBulb = Bulb::create([
            'local_node_id' => $existingNodeId,
            'mac' => 'AA:BB:CC:DD:EE:03',
            'ip' => '192.168.1.12',
            'name' => 'Existing Bulb',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -55,
        ]);

        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:03',
                'ip' => '192.168.1.13',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:03', 'state' => true, 'dimming' => 50, 'r' => 255, 'g' => 0, 'b' => 0, 'rssi' => -40], 'from' => '192.168.1.13'],
                ],
                'sysconfig_response' => [],
            ],
        ];

        $responses = $this->buildDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        $job = new BulbDiscovery();
        $job->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:03')->first();
        $this->assertNotNull($bulb);
        $this->assertEquals($existingNodeId, $bulb->local_node_id, 'Ownership should not be overwritten by another node');
    }

    /** @test */
    public function discovery_skips_when_lock_is_held()
    {
        $lock = Cache::store('testing')->lock('bulb-discovery', 30);

        $lock->get(function () {
            $bulbData = [
                [
                    'mac' => 'AA:BB:CC:DD:EE:04',
                    'ip' => '192.168.1.14',
                    'pilot_response' => [],
                    'sysconfig_response' => [],
                ],
            ];

            $responses = $this->buildDiscoveryResponses($bulbData);
            $transport = $this->makeMockTransport($responses);
            app()->instance(UdpTransport::class, $transport);

            $job = new BulbDiscovery();
            $job->handle();

            $this->assertCount(0, Bulb::all(), 'No bulb should be created when lock is held');
        });
    }

    /** @test */
    public function discovery_updates_ip_for_own_bulb()
    {
        $thisNode = (string) \Illuminate\Support\Str::uuid();
        Bulb::create([
            'local_node_id' => $thisNode,
            'mac' => 'AA:BB:CC:DD:EE:05',
            'ip' => '192.168.1.20',
            'name' => 'My Bulb',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -50,
        ]);

        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:05',
                'ip' => '192.168.1.21',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:05', 'state' => true, 'dimming' => 90, 'r' => 100, 'g' => 100, 'b' => 100, 'rssi' => -48], 'from' => '192.168.1.21'],
                ],
                'sysconfig_response' => [],
            ],
        ];

        $responses = $this->buildDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        // Also need to set clarion.node_id to match $thisNode for "own bulb" logic
        config(['clarion.node_id' => $thisNode]);

        $job = new BulbDiscovery();
        $job->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:05')->first();
        $this->assertNotNull($bulb);
        $this->assertEquals('192.168.1.21', $bulb->ip, 'IP should be updated');
        $this->assertEquals($thisNode, $bulb->local_node_id);
    }

    /** @test */
    public function lapsed_node_ownership_is_reclaimed()
    {
        config(['wizlight.ownership.lapse_hours' => 24]);

        $otherNodeId = (string) \Illuminate\Support\Str::uuid();
        $existingBulb = Bulb::create([
            'local_node_id' => $otherNodeId,
            'mac' => 'AA:BB:CC:DD:EE:06',
            'ip' => '192.168.1.30',
            'name' => 'Lapsed Bulb',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -55,
        ]);

        $existingBulb->updated_at = now()->subHours(25);
        $existingBulb->save();

        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:06',
                'ip' => '192.168.1.31',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:06', 'state' => true, 'dimming' => 60, 'r' => 100, 'g' => 200, 'b' => 50, 'rssi' => -42], 'from' => '192.168.1.31'],
                ],
                'sysconfig_response' => [],
            ],
        ];

        $responses = $this->buildDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        $job = new BulbDiscovery();
        $job->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:06')->first();
        $this->assertNotNull($bulb);
        $this->assertEquals('test-node-001', $bulb->local_node_id, 'Ownership should be reclaimed from lapsed node');
        $this->assertEquals('192.168.1.31', $bulb->ip, 'IP should be updated after reclaim');
    }

    /** @test */
    public function active_node_ownership_is_not_reclaimed()
    {
        config(['wizlight.ownership.lapse_hours' => 24]);

        $otherNodeId = (string) \Illuminate\Support\Str::uuid();
        $originalIp = '192.168.1.40';
        $originalState = false;
        $originalDimming = 100;
        Bulb::create([
            'local_node_id' => $otherNodeId,
            'mac' => 'AA:BB:CC:DD:EE:07',
            'ip' => $originalIp,
            'name' => 'Active Owner Bulb',
            'state' => $originalState,
            'dimming' => $originalDimming,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -55,
        ]);

        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:07',
                'ip' => '192.168.1.41',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:07', 'state' => true, 'dimming' => 50, 'r' => 255, 'g' => 0, 'b' => 0, 'rssi' => -40], 'from' => '192.168.1.41'],
                ],
                'sysconfig_response' => [],
            ],
        ];

        $responses = $this->buildDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        $job = new BulbDiscovery();
        $job->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:07')->first();
        $this->assertNotNull($bulb);
        $this->assertEquals($otherNodeId, $bulb->local_node_id, 'Ownership should not change for active node');
        $this->assertEquals($originalIp, $bulb->ip, 'IP should not be updated when skipped');
        $this->assertEquals($originalState, (bool) $bulb->state, 'State should not be updated when skipped');
        $this->assertEquals($originalDimming, $bulb->dimming, 'Dimming should not be updated when skipped');
    }
}
