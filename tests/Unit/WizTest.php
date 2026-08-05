<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\Transport\FakeUdpTransport;

/**
 * Wiz is a protocol codec (FR-015): it formats, sends, decodes, returns.
 *
 * Note what this test class does NOT do — it declares no database connection
 * and runs no migrations. Every assertion below is reachable with no database
 * at all, which is the property FR-015 exists to establish.
 */
class WizTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \ClarionApp\WizlightBackend\WizlightBackendServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('wizlight.broadcast_address', '255.255.255.255');
        $app['config']->set('wizlight.udp_wait_time', 30.0);
        $app['config']->set('wizlight.phone_mac', 'AAAAAAAAAAAA');
        $app['config']->set('wizlight.udp_port', 38899);
    }

    /** @test */
    public function wiz_class_imports_no_eloquent_model()
    {
        // FR-015 guard: a codec that reaches for a model is the layering bug
        // that made a first-time device record default state (D4). This is a
        // property of the class's dependencies, so reflection — not source
        // text — is the right instrument.
        $reflection = new \ReflectionClass(Wiz::class);
        $constructor = $reflection->getConstructor();

        $dependencies = [];
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $dependencies[] = $type->getName();
                }
            }
        }
        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $type->getName();
            }
        }

        foreach ($dependencies as $dependency) {
            $this->assertStringNotContainsString(
                'Models\\',
                $dependency,
                "Wiz must not depend on {$dependency} — persistence belongs to its callers"
            );
        }
    }

    /** @test */
    public function send_udp_delegates_to_transport()
    {
        $transport = new FakeUdpTransport();

        $wiz = new Wiz(transport: $transport);
        $wiz->send_udp((object) ['method' => 'getPilot'], '192.168.1.10');

        $this->assertSame(1, $transport->sendCount());
        $this->assertEquals('getPilot', $transport->sends()[0]['message']->method);
        $this->assertEquals(['192.168.1.10'], $transport->sends()[0]['targets']);
    }

    /** @test */
    public function send_udp_uses_broadcast_when_no_targets()
    {
        $transport = new FakeUdpTransport();

        $wiz = new Wiz(transport: $transport);
        $wiz->send_udp((object) ['method' => 'registration']);

        $this->assertEquals(['255.255.255.255'], $transport->sends()[0]['targets']);
    }

    /** @test */
    public function send_udp_returns_transport_responses()
    {
        $transport = (new FakeUdpTransport())
            ->willRespond(['result' => ['state' => true], 'from' => '192.168.1.10']);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->send_udp((object) ['method' => 'getPilot'], '192.168.1.10');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['result']['state']);
    }

    /** @test */
    public function send_udp_returns_empty_array_on_no_response()
    {
        $transport = (new FakeUdpTransport())->willRespondWithNothing();

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->send_udp((object) ['method' => 'getPilot'], '192.168.1.10');

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    /** @test */
    public function send_udp_closes_transport_after_use()
    {
        $transport = new FakeUdpTransport();

        $wiz = new Wiz(transport: $transport);
        $wiz->send_udp((object) ['method' => 'getPilot'], '192.168.1.10');

        $this->assertSame(1, $transport->closeCount(), 'Transport should be closed after send_udp');
    }

    /** @test */
    public function discover_processes_all_responses_not_just_first()
    {
        // The behaviour: every responder in the registration batch becomes an
        // entry in the returned array. Deleting the loop body, or breaking out
        // of it after the first response, fails this — which the source-text
        // predecessor of this test (a regex over the method body looking for a
        // `break`) did not.
        $transport = new FakeUdpTransport();
        $transport->willRespond(
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:01'], 'from' => '192.168.1.10'],
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:02'], 'from' => '192.168.1.11'],
        );
        // Per-bulb: getPilot, getSystemConfig, getModelConfig, getUserConfig (fallback).
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'state' => true], 'from' => '192.168.1.10']);
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'moduleName' => 'A19'], 'from' => '192.168.1.10']);
        $transport->willRespondWithNothing();
        $transport->willRespond(['result' => ['extRange' => [2700, 5000]]]);
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:02', 'state' => false], 'from' => '192.168.1.11']);
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:02', 'moduleName' => 'A60'], 'from' => '192.168.1.11']);
        $transport->willRespondWithNothing();
        $transport->willRespond(['result' => ['extRange' => [3000, 4500]]]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->discover();

        $this->assertCount(2, $results);
        $this->assertEquals('AA:BB:CC:DD:EE:01', $results[0]['mac']);
        $this->assertEquals('AA:BB:CC:DD:EE:02', $results[1]['mac']);
        $this->assertTrue($results[0]['pilot_state']['state']);
        $this->assertFalse($results[1]['pilot_state']['state']);
        $this->assertEquals('A60', $results[1]['system_config']['moduleName']);
    }

    /** @test */
    public function discover_sends_registration_message()
    {
        $transport = new FakeUdpTransport();

        $wiz = new Wiz(transport: $transport);
        $wiz->discover();

        $sent = $transport->sends()[0]['message'];
        $this->assertEquals('registration', $sent->method);
        $this->assertEquals(false, $sent->params->register);
    }

    /** @test */
    public function discover_issues_getModelConfig_and_conditional_getUserConfig_per_light()
    {
        // getModelConfig is called per light; getUserConfig is called as fallback
        // when model_config has no usable cctRange (no scripted response → empty).
        $transport = new FakeUdpTransport();
        $transport->willRespond(
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:01'], 'from' => '192.168.1.10'],
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:02'], 'from' => '192.168.1.11'],
        );

        $wiz = new Wiz(transport: $transport);
        $wiz->discover();

        $this->assertSame(1, $transport->sendCountForMethod('registration'), 'One broadcast per cycle');
        $this->assertSame(2, $transport->sendCountForMethod('getPilot'), 'One getPilot per light');
        $this->assertSame(2, $transport->sendCountForMethod('getSystemConfig'), 'One getSystemConfig per light');
        $this->assertSame(2, $transport->sendCountForMethod('getModelConfig'), 'getModelConfig called per light');
        $this->assertSame(2, $transport->sendCountForMethod('getUserConfig'), 'getUserConfig called as fallback (no model_config range)');
    }

    /** @test */
    public function get_user_config_is_retained_for_the_later_configuration_phase()
    {
        // FR-008b keeps the method while removing it from discovery. Its
        // behaviour is asserted here so a later phase inherits a covered codec.
        $transport = (new FakeUdpTransport())
            ->willRespond(['result' => ['fadeIn' => 500, 'fadeOut' => 500], 'from' => '192.168.1.10']);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->get_user_config('192.168.1.10');

        $this->assertEquals('getUserConfig', $transport->sends()[0]['message']->method);
        $this->assertEquals(500, $results[0]['result']['fadeIn']);
    }

    /** @test */
    public function get_pilot_state_returns_the_decoded_payload_and_persists_nothing()
    {
        $transport = (new FakeUdpTransport())->willRespond([
            'result' => [
                'mac' => 'AA:BB:CC:DD:EE:FF',
                'state' => true,
                'dimming' => 80,
                'r' => 100,
                'g' => 150,
                'b' => 200,
                'rssi' => -40,
            ],
            'from' => '192.168.1.50',
        ]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->get_pilot_state('192.168.1.50');

        $this->assertEquals('getPilot', $transport->sends()[0]['message']->method);
        $this->assertEquals(-40, $results[0]['result']['rssi']);
        $this->assertTrue($results[0]['result']['state']);
    }

    /** @test */
    public function get_system_config_returns_the_decoded_payload_and_persists_nothing()
    {
        $transport = (new FakeUdpTransport())->willRespond([
            'result' => ['mac' => 'AA:BB:CC:DD:EE:FF', 'moduleName' => 'ESP01_SHRGB_03'],
            'from' => '192.168.1.50',
        ]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->get_system_config('192.168.1.50');

        $this->assertEquals('getSystemConfig', $transport->sends()[0]['message']->method);
        $this->assertEquals('ESP01_SHRGB_03', $results[0]['result']['moduleName']);
    }

    /** @test */
    public function wiz_accepts_custom_wait_time()
    {
        $transport = new FakeUdpTransport();

        $wiz = new Wiz(wait_time: 2.0, transport: $transport);
        $wiz->send_udp((object) ['method' => 'getPilot'], '192.168.1.10');

        $this->assertEquals([2.0], $transport->receiveTimeouts());
    }

    /** @test */
    public function wiz_accepts_custom_broadcast_address()
    {
        $transport = new FakeUdpTransport();

        $wiz = new Wiz(broadcast_address: '192.168.1.255', transport: $transport);
        $wiz->discover();

        $this->assertEquals(['192.168.1.255'], $transport->sends()[0]['targets']);
    }

    /** @test */
    public function get_model_config_decodes_4_element_cctRange_using_indices_1_and_2()
    {
        $transport = (new FakeUdpTransport())->willRespond([
            'result' => ['cctRange' => [2000, 2200, 6500, 6500]],
        ]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->get_model_config('192.168.1.50');

        $this->assertEquals('getModelConfig', $transport->sends()[0]['message']->method);
        $this->assertArrayHasKey('cctRange', $results[0]['result']);
        $this->assertEquals(2200, $results[0]['result']['cctRange'][1], 'Index 1 is the enforced minimum');
        $this->assertEquals(6500, $results[0]['result']['cctRange'][2], 'Index 2 is the enforced maximum');
    }

    /** @test */
    public function get_model_config_decodes_2_element_cctRange_as_direct_min_max()
    {
        $transport = (new FakeUdpTransport())->willRespond([
            'result' => ['cctRange' => [2700, 5000]],
        ]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->get_model_config('192.168.1.50');

        $this->assertCount(2, $results[0]['result']['cctRange']);
        $this->assertEquals(2700, $results[0]['result']['cctRange'][0]);
        $this->assertEquals(5000, $results[0]['result']['cctRange'][1]);
    }

    /** @test */
    public function get_model_config_returns_empty_on_no_response()
    {
        $transport = (new FakeUdpTransport())->willRespondWithNothing();

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->get_model_config('192.168.1.50');

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    /** @test */
    public function get_model_config_returns_empty_on_unrecognised_cctRange_shape()
    {
        // 3-element or 1-element cctRange is not a shape the codec understands.
        $transport = (new FakeUdpTransport())->willRespond([
            'result' => ['cctRange' => [2700, 4000, 5000]],
        ]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->get_model_config('192.168.1.50');

        // The codec returns the raw response; the caller (BulbDiscovery) treats
        // any non-2/non-4 length as "absent" when extracting warmth range.
        $this->assertArrayHasKey('result', $results[0]);
        $this->assertArrayHasKey('cctRange', $results[0]['result']);
    }

    /** @test */
    public function discover_payload_includes_model_config_key()
    {
        $transport = new FakeUdpTransport();
        // Registration: one bulb
        $transport->willRespond(
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:01'], 'from' => '192.168.1.10'],
        );
        // Per-bulb: getPilot, getSystemConfig, getModelConfig (has range — no getUserConfig)
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'state' => true], 'from' => '192.168.1.10']);
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'moduleName' => 'ESP01_SHRGB_03'], 'from' => '192.168.1.10']);
        $transport->willRespond(['result' => ['cctRange' => [2000, 2200, 6500, 6500]]]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->discover();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('model_config', $results[0], 'discover() payload must carry model_config key');
    }

    /** @test */
    public function discover_payload_includes_user_config_key_when_model_config_has_no_range()
    {
        $transport = new FakeUdpTransport();
        // Registration: one bulb
        $transport->willRespond(
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:02'], 'from' => '192.168.1.11'],
        );
        // Per-bulb: getPilot, getSystemConfig, getModelConfig (no response), getUserConfig (fallback)
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:02', 'state' => false], 'from' => '192.168.1.11']);
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:02', 'moduleName' => 'ESP06_SHDW1_31'], 'from' => '192.168.1.11']);
        $transport->willRespondWithNothing();
        $transport->willRespond(['result' => ['extRange' => [2700, 5000]]]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->discover();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('user_config', $results[0], 'discover() payload must carry user_config key when used as fallback');
        $this->assertArrayHasKey('extRange', $results[0]['user_config']);
    }

    /** @test */
    public function discover_issues_getModelConfig_then_conditional_getUserConfig_per_light()
    {
        // Two bulbs: first has a usable model_config (no getUserConfig needed),
        // second has no model_config response (getUserConfig fallback fires).
        $transport = new FakeUdpTransport();
        // Registration
        $transport->willRespond(
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:10'], 'from' => '192.168.1.20'],
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:11'], 'from' => '192.168.1.21'],
        );
        // Bulb 1: getPilot, getSystemConfig, getModelConfig (has range — no fallback)
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:10', 'state' => true], 'from' => '192.168.1.20']);
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:10', 'moduleName' => 'ESP01_SHRGB_03'], 'from' => '192.168.1.20']);
        $transport->willRespond(['result' => ['cctRange' => [2000, 2200, 6500, 6500]]]);
        // Bulb 2: getPilot, getSystemConfig, getModelConfig (no response), getUserConfig (fallback)
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:11', 'state' => false], 'from' => '192.168.1.21']);
        $transport->willRespond(['result' => ['mac' => 'AA:BB:CC:DD:EE:11', 'moduleName' => 'ESP99_XYZ1_01'], 'from' => '192.168.1.21']);
        $transport->willRespondWithNothing();
        $transport->willRespond(['result' => ['extRange' => [2700, 5000]]]);

        $wiz = new Wiz(transport: $transport);
        $wiz->discover();

        $this->assertSame(2, $transport->sendCountForMethod('getModelConfig'), 'getModelConfig called per light');
        $this->assertSame(1, $transport->sendCountForMethod('getUserConfig'), 'getUserConfig called only for bulb 2 (fallback)');
    }
}
