<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Jobs\BulbDiscovery;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\Transport\FakeUdpTransport;
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
            'local_node_seen_at' => now(),
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
            'local_node_seen_at' => now()->subHours(25),
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
            'local_node_seen_at' => now(),
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

    /** @test */
    public function claim_lapses_even_when_another_node_keeps_touching_the_row()
    {
        // The case that distinguishes the local_node_seen_at rule from an
        // updated_at rule (FR-009). The owner has been silent past the window,
        // but some other node's write — or a status check — has refreshed
        // updated_at seconds ago. Under an updated_at rule the claim would
        // never lapse and the device would stay uncontrollable forever.
        config(['wizlight.ownership.lapse_hours' => 24]);

        $deadNodeId = (string) \Illuminate\Support\Str::uuid();
        $bulb = Bulb::create([
            'local_node_id' => $deadNodeId,
            'local_node_seen_at' => now()->subHours(30),
            'mac' => 'AA:BB:CC:DD:EE:08',
            'ip' => '192.168.1.50',
            'name' => 'Orphaned Bulb',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -55,
        ]);

        // Somebody else touches the row right now. updated_at is fresh.
        $bulb->signal = -44;
        $bulb->save();
        $this->assertTrue(
            $bulb->fresh()->updated_at->greaterThan(now()->subMinute()),
            'Precondition: updated_at is fresh'
        );

        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:08',
                'ip' => '192.168.1.51',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:08', 'state' => true, 'dimming' => 70, 'r' => 10, 'g' => 20, 'b' => 30, 'rssi' => -41], 'from' => '192.168.1.51'],
                ],
                'sysconfig_response' => [],
            ],
        ];

        app()->instance(UdpTransport::class, $this->makeMockTransport($this->buildDiscoveryResponses($bulbData)));

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:08')->first();
        $this->assertEquals('test-node-001', $bulb->local_node_id, 'A silent owner lapses regardless of who else touched the row');
        $this->assertNotNull($bulb->local_node_seen_at);
        $this->assertTrue(
            $bulb->local_node_seen_at->greaterThan(now()->subMinute()),
            'Reclaim writes owner and a fresh liveness timestamp in the same update'
        );
    }

    /** @test */
    public function a_claim_with_no_liveness_timestamp_is_treated_as_lapsed()
    {
        // Rows written before local_node_seen_at existed. The first node to run
        // discovery after upgrading establishes a real timestamp.
        $legacyNodeId = (string) \Illuminate\Support\Str::uuid();
        Bulb::create([
            'local_node_id' => $legacyNodeId,
            'local_node_seen_at' => null,
            'mac' => 'AA:BB:CC:DD:EE:09',
            'ip' => '192.168.1.60',
            'name' => 'Legacy Bulb',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -55,
        ]);

        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:09',
                'ip' => '192.168.1.60',
                'pilot_response' => [],
                'sysconfig_response' => [],
            ],
        ];

        app()->instance(UdpTransport::class, $this->makeMockTransport($this->buildDiscoveryResponses($bulbData)));

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:09')->first();
        $this->assertEquals('test-node-001', $bulb->local_node_id);
        $this->assertNotNull($bulb->local_node_seen_at, 'The reclaiming node establishes the timestamp');
    }

    /** @test */
    public function new_bulb_is_claimed_with_a_liveness_timestamp()
    {
        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:0A',
                'ip' => '192.168.1.70',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:0A', 'state' => true, 'dimming' => 55, 'r' => 1, 'g' => 2, 'b' => 3, 'rssi' => -33], 'from' => '192.168.1.70'],
                ],
                'sysconfig_response' => [],
            ],
        ];

        app()->instance(UdpTransport::class, $this->makeMockTransport($this->buildDiscoveryResponses($bulbData)));

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:0A')->first();
        $this->assertEquals('test-node-001', $bulb->local_node_id);
        $this->assertNotNull($bulb->local_node_seen_at, 'A first claim stamps liveness alongside ownership');
    }

    /** @test */
    public function owner_renews_its_own_claim_once_it_has_aged()
    {
        config(['wizlight.ownership.heartbeat_minutes' => 60]);

        $stamped = now()->subHours(3);
        Bulb::create([
            'local_node_id' => 'test-node-001',
            'local_node_seen_at' => $stamped,
            'mac' => 'AA:BB:CC:DD:EE:0B',
            'ip' => '192.168.1.80',
            'name' => 'My Aging Bulb',
            'state' => false,
            'dimming' => 100,
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'signal' => -55,
        ]);

        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:0B',
                'ip' => '192.168.1.80',
                'pilot_response' => [],
                'sysconfig_response' => [],
            ],
        ];

        app()->instance(UdpTransport::class, $this->makeMockTransport($this->buildDiscoveryResponses($bulbData)));

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:0B')->first();
        $this->assertTrue(
            $bulb->local_node_seen_at->greaterThan($stamped),
            'The owner must advance its own liveness timestamp, or its claim eventually lapses under it'
        );
    }

    /** @test */
    public function owner_does_not_rewrite_a_fresh_claim_on_an_unchanged_bulb()
    {
        // Renewing every cycle would put a bridged on-chain write on every
        // light every minute — the cost the no-change rule exists to avoid.
        config(['wizlight.ownership.heartbeat_minutes' => 60]);

        $bulb = Bulb::create([
            'local_node_id' => 'test-node-001',
            'local_node_seen_at' => now()->subMinutes(5),
            'mac' => 'AA:BB:CC:DD:EE:0C',
            'ip' => '192.168.1.90',
            'name' => 'My Fresh Bulb',
            'state' => true,
            'dimming' => 42,
            'red' => 7,
            'green' => 8,
            'blue' => 9,
            'signal' => -37,
            'firmware_version' => '',
            'capability_class' => 'dim_only',
            'warmth_min_kelvin' => null,
            'warmth_max_kelvin' => null,
            'wiz_room_id' => null,
            'wiz_group_id' => null,
        ]);
        $originalUpdatedAt = $bulb->fresh()->updated_at;

        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:0C',
                'ip' => '192.168.1.90',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:0C', 'state' => true, 'dimming' => 42, 'r' => 7, 'g' => 8, 'b' => 9, 'rssi' => -37], 'from' => '192.168.1.90'],
                ],
                'sysconfig_response' => [],
            ],
        ];

        app()->instance(UdpTransport::class, $this->makeMockTransport($this->buildDiscoveryResponses($bulbData)));

        sleep(1);
        (new BulbDiscovery())->handle();

        $this->assertEquals(
            $originalUpdatedAt,
            Bulb::where('mac', 'AA:BB:CC:DD:EE:0C')->first()->updated_at,
            'An unchanged bulb with a fresh claim must cost zero writes'
        );
    }

    /** @test */
    public function discovery_issues_getModelConfig_and_conditional_getUserConfig_per_light()
    {
        // getModelConfig is called per light; getUserConfig is called as fallback
        // when model_config has no usable cctRange (no scripted response → empty).
        $transport = new FakeUdpTransport();
        $transport->willRespond(
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:0D'], 'from' => '192.168.1.100'],
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:0E'], 'from' => '192.168.1.101'],
        );

        app()->instance(UdpTransport::class, $transport);

        (new BulbDiscovery())->handle();

        $this->assertSame(2, $transport->sendCountForMethod('getPilot'));
        $this->assertSame(2, $transport->sendCountForMethod('getSystemConfig'));
        $this->assertSame(2, $transport->sendCountForMethod('getModelConfig'), 'getModelConfig called per light');
        $this->assertSame(2, $transport->sendCountForMethod('getUserConfig'), 'getUserConfig called as fallback when model_config has no range');
    }

    /**
     * Build discovery responses including getModelConfig and getUserConfig calls.
     *
     * Each bulb entry can carry:
     * - model_config_response: array of datagrams for getModelConfig (or [] for timeout)
     * - user_config_response: array of datagrams for getUserConfig fallback (or [] for no call)
     */
    private function buildExtendedDiscoveryResponses(array $bulbs): array
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

        foreach ($bulbs as $b) {
            $responses[] = $b['pilot_response'] ?? [];
            $responses[] = $b['sysconfig_response'] ?? [];
            $responses[] = $b['model_config_response'] ?? [];
            $responses[] = $b['user_config_response'] ?? [];
        }

        return $responses;
    }

    /** @test */
    public function discovery_records_full_colour_capability_from_rgb_module()
    {
        // Fixture: full-colour device from contracts/device-capability-protocol.md
        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:01',
                'ip' => '192.168.1.10',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'state' => true, 'dimming' => 80, 'r' => 255, 'g' => 120, 'b' => 0, 'rssi' => -55], 'from' => '192.168.1.10'],
                ],
                'sysconfig_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'moduleName' => 'ESP01_SHRGB_03', 'fwVersion' => '1.25.0', 'homeId' => 111, 'roomId' => 1, 'groupId' => 2], 'from' => '192.168.1.10'],
                ],
                'model_config_response' => [
                    ['result' => ['cctRange' => [2000, 2200, 6500, 6500]]],
                ],
                'user_config_response' => [],
            ],
        ];

        $responses = $this->buildExtendedDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:01')->first();
        $this->assertNotNull($bulb);
        $this->assertEquals('ESP01_SHRGB_03', $bulb->model);
        $this->assertEquals('1.25.0', $bulb->firmware_version);
        $this->assertEquals('full_colour', $bulb->capability_class);
        $this->assertEquals(2200, $bulb->warmth_min_kelvin);
        $this->assertEquals(6500, $bulb->warmth_max_kelvin);
        $this->assertEquals(1, $bulb->wiz_room_id);
        $this->assertEquals(2, $bulb->wiz_group_id);
    }

    /** @test */
    public function discovery_records_tunable_white_capability_from_tw_module()
    {
        // Fixture: tunable-white device from contracts/device-capability-protocol.md
        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:02',
                'ip' => '192.168.1.11',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:02', 'state' => true, 'dimming' => 60, 'temperature' => 3000, 'rssi' => -62], 'from' => '192.168.1.11'],
                ],
                'sysconfig_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:02', 'moduleName' => 'ESP03_SHTW1_01ABI', 'fwVersion' => '1.22.0', 'homeId' => 111, 'roomId' => 1, 'groupId' => 3], 'from' => '192.168.1.11'],
                ],
                'model_config_response' => [
                    ['result' => ['cctRange' => [2200, 2700, 5000, 5500]]],
                ],
                'user_config_response' => [],
            ],
        ];

        $responses = $this->buildExtendedDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:02')->first();
        $this->assertNotNull($bulb);
        $this->assertEquals('ESP03_SHTW1_01ABI', $bulb->model);
        $this->assertEquals('1.22.0', $bulb->firmware_version);
        $this->assertEquals('tunable_white', $bulb->capability_class);
        $this->assertEquals(2700, $bulb->warmth_min_kelvin);
        $this->assertEquals(5000, $bulb->warmth_max_kelvin);
        $this->assertEquals(1, $bulb->wiz_room_id);
        $this->assertEquals(3, $bulb->wiz_group_id);
    }

    /** @test */
    public function discovery_records_dim_only_capability_from_dw_module()
    {
        // Fixture: dim-only device from contracts/device-capability-protocol.md
        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:03',
                'ip' => '192.168.1.12',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:03', 'state' => true, 'dimming' => 45, 'rssi' => -70], 'from' => '192.168.1.12'],
                ],
                'sysconfig_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:03', 'moduleName' => 'ESP06_SHDW1_31', 'fwVersion' => '1.20.0', 'homeId' => 111, 'roomId' => 2, 'groupId' => 0], 'from' => '192.168.1.12'],
                ],
                'model_config_response' => [],
                'user_config_response' => [],
            ],
        ];

        $responses = $this->buildExtendedDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:03')->first();
        $this->assertNotNull($bulb);
        $this->assertEquals('ESP06_SHDW1_31', $bulb->model);
        $this->assertEquals('1.20.0', $bulb->firmware_version);
        $this->assertEquals('dim_only', $bulb->capability_class);
        $this->assertNull($bulb->warmth_min_kelvin);
        $this->assertNull($bulb->warmth_max_kelvin);
        $this->assertEquals(2, $bulb->wiz_room_id);
        $this->assertEquals(0, $bulb->wiz_group_id);
    }

    /** @test */
    public function discovery_upgrades_unknown_model_to_tunable_white_via_user_config_range()
    {
        // Fixture: unknown model with warmth-range upgrade from contracts/device-capability-protocol.md
        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:04',
                'ip' => '192.168.1.13',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:04', 'state' => false, 'dimming' => 100, 'rssi' => -80], 'from' => '192.168.1.13'],
                ],
                'sysconfig_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:04', 'moduleName' => 'ESP99_XYZ1_01', 'fwVersion' => '0.9.9', 'homeId' => 111, 'roomId' => 3, 'groupId' => 1], 'from' => '192.168.1.13'],
                ],
                'model_config_response' => [],
                'user_config_response' => [
                    ['result' => ['extRange' => [2700, 5000]]],
                ],
            ],
        ];

        $responses = $this->buildExtendedDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:04')->first();
        $this->assertNotNull($bulb);
        $this->assertEquals('ESP99_XYZ1_01', $bulb->model, 'Raw moduleName persisted verbatim (FR-001)');
        $this->assertEquals('0.9.9', $bulb->firmware_version);
        $this->assertEquals('tunable_white', $bulb->capability_class, 'Unknown name + non-equal range upgrades to tunable_white');
        $this->assertEquals(2700, $bulb->warmth_min_kelvin);
        $this->assertEquals(5000, $bulb->warmth_max_kelvin);
        $this->assertEquals(3, $bulb->wiz_room_id);
        $this->assertEquals(1, $bulb->wiz_group_id);
    }

    /** @test */
    public function discovery_creates_bulb_with_fallback_values_when_getSystemConfig_times_out()
    {
        // Fixture: getSystemConfig times out (FR-015, partial data)
        // from contracts/device-capability-protocol.md
        $bulbData = [
            [
                'mac' => 'AA:BB:CC:DD:EE:05',
                'ip' => '192.168.1.14',
                'pilot_response' => [
                    ['result' => ['mac' => 'AA:BB:CC:DD:EE:05', 'state' => true, 'dimming' => 50, 'rssi' => -65], 'from' => '192.168.1.14'],
                ],
                'sysconfig_response' => [],
                'model_config_response' => [],
                'user_config_response' => [],
            ],
        ];

        $responses = $this->buildExtendedDiscoveryResponses($bulbData);
        $transport = $this->makeMockTransport($responses);
        app()->instance(UdpTransport::class, $transport);

        (new BulbDiscovery())->handle();

        $bulb = Bulb::where('mac', 'AA:BB:CC:DD:EE:05')->first();
        $this->assertNotNull($bulb, 'Device is still created even when getSystemConfig times out (FR-015)');
        $this->assertEquals('', $bulb->firmware_version, 'Missing firmware_version defaults to empty string');
        $this->assertEquals('dim_only', $bulb->capability_class, 'No moduleName → dim_only fallback');
        $this->assertNull($bulb->warmth_min_kelvin);
        $this->assertNull($bulb->warmth_max_kelvin);
        $this->assertNull($bulb->wiz_room_id);
        $this->assertNull($bulb->wiz_group_id);
    }
}
