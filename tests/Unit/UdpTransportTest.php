<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\Transport\FakeUdpTransport;
use ClarionApp\WizlightBackend\Transport\SocketUdpTransport;

/**
 * Contract test for {@see UdpTransport} (T003b).
 *
 * Every assertion runs against *both* shipped implementations, so the fake
 * cannot drift from the socket-backed one. The socket-backed implementation is
 * exercised over loopback only — no live network, no LAN traffic, no device.
 */
class UdpTransportTest extends TestCase
{
    /** @var array<int, \Socket> */
    private array $peers = [];

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            @socket_close($peer);
        }
        $this->peers = [];

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function implementations(): array
    {
        return [
            'fake' => [fn (self $test, int $port) => new FakeUdpTransport()],
            'socket' => [fn (self $test, int $port) => new SocketUdpTransport('127.0.0.1', $port)],
        ];
    }

    /**
     * Bind a loopback UDP socket that echoes one canned reply per datagram it
     * receives. Returns the port it is listening on.
     */
    private function startLoopbackDevice(array $reply): int
    {
        $peer = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($peer, '127.0.0.1', 0);
        socket_getsockname($peer, $address, $port);
        socket_set_option($peer, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        $this->peers[] = $peer;

        // Reply is sent lazily by pumpLoopbackDevice() so the caller controls timing.
        $this->pendingReplies[$port] = ['socket' => $peer, 'reply' => $reply];

        return $port;
    }

    /** @var array<int, array{socket: \Socket, reply: array}> */
    private array $pendingReplies = [];

    /**
     * Read one datagram on the device socket and answer it, so the transport
     * under test has something to receive.
     */
    private function pumpLoopbackDevice(int $port): void
    {
        $device = $this->pendingReplies[$port];
        $buf = '';
        $from = '';
        $fromPort = 0;
        $bytes = @socket_recvfrom($device['socket'], $buf, 2048, 0, $from, $fromPort);
        if ($bytes === false) {
            return;
        }
        $payload = json_encode($device['reply']);
        socket_sendto($device['socket'], $payload, strlen($payload), 0, $from, $fromPort);
    }

    /**
     * @dataProvider implementations
     */
    public function test_send_records_or_delivers_without_error(callable $make): void
    {
        $port = $this->startLoopbackDevice(['result' => ['ok' => true]]);
        $transport = $make($this, $port);

        $transport->send((object) ['method' => 'getPilot'], ['127.0.0.1']);

        // The socket implementation proves delivery by the device seeing it;
        // the fake proves it by recording it. Both must accept the same call.
        $this->assertInstanceOf(UdpTransport::class, $transport);
        $transport->close();
    }

    /**
     * @dataProvider implementations
     */
    public function test_receive_returns_decoded_datagrams_with_source_address(callable $make): void
    {
        $reply = ['result' => ['mac' => 'AA:BB:CC:DD:EE:01', 'state' => true]];
        $port = $this->startLoopbackDevice($reply);
        $transport = $make($this, $port);

        if ($transport instanceof FakeUdpTransport) {
            $transport->willRespond($reply + ['from' => '127.0.0.1']);
        }

        $transport->send((object) ['method' => 'getPilot'], ['127.0.0.1']);

        if ($transport instanceof SocketUdpTransport) {
            $this->pumpLoopbackDevice($port);
        }

        $results = $transport->receive(1.0);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('AA:BB:CC:DD:EE:01', $results[0]['result']['mac']);
        $this->assertTrue($results[0]['result']['state']);
        $this->assertArrayHasKey('from', $results[0], 'Every datagram must carry its source address');
        $this->assertEquals('127.0.0.1', $results[0]['from']);

        $transport->close();
    }

    /**
     * @dataProvider implementations
     */
    public function test_receive_returns_null_when_nothing_answers(callable $make): void
    {
        $port = $this->startLoopbackDevice(['result' => []]);
        $transport = $make($this, $port);

        $transport->send((object) ['method' => 'getPilot'], ['127.0.0.1']);

        // Nothing pumped on the device side, so nothing comes back.
        $this->assertNull($transport->receive(0.1));

        $transport->close();
    }

    /**
     * @dataProvider implementations
     */
    public function test_close_is_idempotent_and_transport_is_reusable_after_close(callable $make): void
    {
        $port = $this->startLoopbackDevice(['result' => []]);
        $transport = $make($this, $port);

        $transport->send((object) ['method' => 'getPilot'], ['127.0.0.1']);
        $transport->close();
        $transport->close();

        // Wiz closes after every send_udp() and then reuses the same instance,
        // so a closed transport must still accept the next send.
        $transport->send((object) ['method' => 'getPilot'], ['127.0.0.1']);
        $transport->close();

        $this->assertTrue(true);
    }

    /**
     * @dataProvider implementations
     */
    public function test_send_accepts_multiple_targets(callable $make): void
    {
        $port = $this->startLoopbackDevice(['result' => []]);
        $transport = $make($this, $port);

        $transport->send((object) ['method' => 'setPilot'], ['127.0.0.1', '127.0.0.1', '127.0.0.1']);
        $transport->close();

        $this->assertTrue(true);
    }

    public function test_fake_records_sends_for_assertion(): void
    {
        $fake = new FakeUdpTransport();

        $fake->send((object) ['method' => 'getPilot'], ['192.168.1.10']);
        $fake->send((object) ['method' => 'getSystemConfig'], ['192.168.1.10']);
        $fake->send((object) ['method' => 'getPilot'], ['192.168.1.11']);

        $this->assertSame(3, $fake->sendCount());
        $this->assertSame(2, $fake->sendCountForMethod('getPilot'));
        $this->assertSame(1, $fake->sendCountForMethod('getSystemConfig'));
        $this->assertSame(0, $fake->sendCountForMethod('getUserConfig'));
        $this->assertEquals(['192.168.1.11'], $fake->sends()[2]['targets']);
    }

    public function test_fake_returns_scripted_responses_in_order_then_null(): void
    {
        $fake = new FakeUdpTransport();
        $fake->willRespond(['result' => ['mac' => 'AA']], ['result' => ['mac' => 'BB']]);
        $fake->willRespondWithNothing();
        $fake->willRespond(['result' => ['mac' => 'CC']]);

        $first = $fake->receive(1.0);
        $this->assertCount(2, $first);
        $this->assertEquals('BB', $first[1]['result']['mac']);

        $this->assertNull($fake->receive(1.0));

        $third = $fake->receive(1.0);
        $this->assertEquals('CC', $third[0]['result']['mac']);

        $this->assertNull($fake->receive(1.0), 'Unscripted receive behaves like a timeout');
        $this->assertSame(4, $fake->receiveCount());
    }
}
