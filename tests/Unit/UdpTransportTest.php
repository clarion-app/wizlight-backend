<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Transport\UdpTransport;

class UdpTransportTest extends TestCase
{
    /** @test */
    public function interface_requires_send_method()
    {
        $mock = $this->createMock(UdpTransport::class);
        $this->assertTrue(method_exists($mock, 'send'));
    }

    /** @test */
    public function interface_requires_receive_method()
    {
        $mock = $this->createMock(UdpTransport::class);
        $this->assertTrue(method_exists($mock, 'receive'));
    }

    /** @test */
    public function send_accepts_message_and_targets()
    {
        $sentMessage = null;
        $sentTargets = null;
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentMessage, &$sentTargets) {
            $sentMessage = $msg;
            $sentTargets = $targets;
        });
        $message = (object)['method' => 'getPilot'];
        $targets = ['192.168.1.10'];

        $mock->send($message, $targets);
        $this->assertEquals('getPilot', $sentMessage->method);
        $this->assertEquals(['192.168.1.10'], $sentTargets);
    }

    /** @test */
    public function receive_returns_array_on_response()
    {
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('receive')->willReturn([
            ['result' => ['state' => true], 'from' => '192.168.1.10'],
        ]);

        $result = $mock->receive(2.0);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('192.168.1.10', $result[0]['from']);
    }

    /** @test */
    public function receive_returns_null_on_timeout()
    {
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('receive')->willReturn(null);

        $result = $mock->receive(2.0);
        $this->assertNull($result);
    }

    /** @test */
    public function send_can_accept_multiple_targets()
    {
        $sentTargets = null;
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentTargets) {
            $sentTargets = $targets;
        });
        $message = (object)['method' => 'setPilot'];
        $targets = ['192.168.1.10', '192.168.1.11', '192.168.1.12'];

        $mock->send($message, $targets);
        $this->assertCount(3, $sentTargets);
        $this->assertEquals('192.168.1.12', $sentTargets[2]);
    }

    /** @test */
    public function send_accepts_broadcast_address()
    {
        $sentTargets = null;
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('send')->willReturnCallback(function ($msg, $targets) use (&$sentTargets) {
            $sentTargets = $targets;
        });
        $message = (object)['method' => 'registration'];
        $targets = ['255.255.255.255'];

        $mock->send($message, $targets);
        $this->assertEquals(['255.255.255.255'], $sentTargets);
    }

    /** @test */
    public function receive_can_return_multiple_responses()
    {
        $mock = $this->createMock(UdpTransport::class);
        $mock->method('receive')->willReturn([
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:01'], 'from' => '192.168.1.10'],
            ['result' => ['mac' => 'AA:BB:CC:DD:EE:02'], 'from' => '192.168.1.11'],
        ]);

        $result = $mock->receive(5.0);
        $this->assertCount(2, $result);
    }
}
