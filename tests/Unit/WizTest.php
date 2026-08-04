<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\RGBColor;

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
    private function makeMockTransport(?array $receiveResponse = null): UdpTransport
    {
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('receive')->willReturn($receiveResponse);
        return $mock;
    }

    /** @test */
    public function send_udp_delegates_to_transport()
    {
        $sentMessage = null;
        $sentTargets = null;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentMessage, &$sentTargets) {
            $sentMessage = $msg;
            $sentTargets = $targets;
        });
        $transport->method('receive')->willReturn(null);

        $wiz = new Wiz(transport: $transport);
        $message = (object)['method' => 'getPilot'];
        $wiz->send_udp($message, '192.168.1.10');

        $this->assertEquals('getPilot', $sentMessage->method);
        $this->assertEquals(['192.168.1.10'], $sentTargets);
    }

    /** @test */
    public function send_udp_uses_broadcast_when_no_targets()
    {
        $sentTargets = null;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentTargets) {
            $sentTargets = $targets;
        });
        $transport->method('receive')->willReturn(null);

        $wiz = new Wiz(transport: $transport);
        $wiz->send_udp((object)['method' => 'registration']);

        $this->assertEquals(['255.255.255.255'], $sentTargets);
    }

    /** @test */
    public function send_udp_returns_transport_responses()
    {
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('receive')->willReturn([
            ['result' => ['state' => true], 'from' => '192.168.1.10'],
        ]);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->send_udp((object)['method' => 'getPilot'], '192.168.1.10');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['result']['state']);
    }

    /** @test */
    public function send_udp_returns_empty_array_on_no_response()
    {
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('receive')->willReturn(null);

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->send_udp((object)['method' => 'getPilot'], '192.168.1.10');

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    /** @test */
    public function send_udp_closes_transport_after_use()
    {
        $closed = false;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('receive')->willReturn(null);
        $transport->method('close')->willReturnCallback(function () use (&$closed) {
            $closed = true;
        });

        $wiz = new Wiz(transport: $transport);
        $wiz->send_udp((object)['method' => 'getPilot'], '192.168.1.10');

        $this->assertTrue($closed, 'Transport should be closed after send_udp');
    }

    /** @test */
    public function discover_processes_all_responses_not_just_first()
    {
        // discover() calls send_udp once for registration, then calls get_pilot_state etc.
        // per bulb. We mock the transport to return two bulbs on the first call
        // (registration), then empty arrays on subsequent calls (getPilot, getUserConfig,
        // getSystemConfig per bulb).
        $callIndex = 0;
        $responses = [
            [
                ['result' => ['mac' => 'AA:BB:CC:DD:EE:01'], 'from' => '192.168.1.10'],
                ['result' => ['mac' => 'AA:BB:CC:DD:EE:02'], 'from' => '192.168.1.11'],
            ],
        ];
        // Subsequent calls (get_pilot_state, get_user_config, get_system_config × 2 bulbs)
        // return empty so Bulb::where() is never reached (bulb not found → early return).
        for ($i = 0; $i < 10; $i++) {
            $responses[] = [];
        }

        $transport = $this->createMock(UdpTransport::class);
        $transport->method('receive')->willReturnCallback(function () use (&$callIndex, &$responses) {
            return $responses[$callIndex++] ?? [];
        });

        $wiz = new Wiz(transport: $transport);
        $results = $wiz->discover();

        $this->assertCount(2, $results);
        $this->assertEquals('AA:BB:CC:DD:EE:01', $results[0]['mac']);
        $this->assertEquals('AA:BB:CC:DD:EE:02', $results[1]['mac']);
    }

    /** @test */
    public function discover_sends_registration_message()
    {
        $sentMessage = null;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentMessage) {
            $sentMessage = $msg;
        });
        $transport->method('receive')->willReturn([]);

        $wiz = new Wiz(transport: $transport);
        $wiz->discover();

        $this->assertEquals('registration', $sentMessage->method);
        $this->assertEquals(false, $sentMessage->params->register);
    }

    /** @test */
    public function set_pilot_state_rgb_branch()
    {
        $sentMessage = null;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentMessage) {
            $sentMessage = $msg;
        });
        $transport->method('receive')->willReturn([]);

        $wiz = new Wiz(transport: $transport);
        $color = new RGBColor(100, 150, 200);
        $wiz->set_pilot_state(['192.168.1.10'], $color, 80, 0, true);

        $this->assertEquals('setPilot', $sentMessage->method);
        $this->assertEquals(100, $sentMessage->params->r);
        $this->assertEquals(150, $sentMessage->params->g);
        $this->assertEquals(200, $sentMessage->params->b);
        $this->assertEquals(80, $sentMessage->params->dimming);
        $this->assertEquals(1, $sentMessage->params->state);
    }

    /** @test */
    public function set_pilot_state_temperature_branch()
    {
        $sentMessage = null;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentMessage) {
            $sentMessage = $msg;
        });
        $transport->method('receive')->willReturn([]);

        $wiz = new Wiz(transport: $transport);
        $color = new RGBColor(0, 0, 0);
        $wiz->set_pilot_state(['192.168.1.10'], $color, 50, 4000, true);

        $this->assertEquals('setPilot', $sentMessage->method);
        $this->assertEquals(0, $sentMessage->params->r);
        $this->assertEquals(4000, $sentMessage->params->temp);
        $this->assertEquals(50, $sentMessage->params->dimming);
    }

    /** @test */
    public function wiz_accepts_custom_wait_time()
    {
        $receivedTimeout = null;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('receive')->willReturnCallback(function ($timeout) use (&$receivedTimeout) {
            $receivedTimeout = $timeout;
            return null;
        });

        $wiz = new Wiz(wait_time: 2.0, transport: $transport);
        $wiz->send_udp((object)['method' => 'getPilot'], '192.168.1.10');

        $this->assertEquals(2.0, $receivedTimeout);
    }

    /** @test */
    public function wiz_accepts_custom_broadcast_address()
    {
        $sentTargets = null;
        $transport = $this->createMock(UdpTransport::class);
        $transport->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentTargets) {
            $sentTargets = $targets;
        });
        $transport->method('receive')->willReturn([]);

        $wiz = new Wiz(broadcast_address: '192.168.1.255', transport: $transport);
        $wiz->discover();

        $this->assertEquals(['192.168.1.255'], $sentTargets);
    }
}
