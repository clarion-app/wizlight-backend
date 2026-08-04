<?php

namespace ClarionApp\WizlightBackend\Transport;

use ClarionApp\WizlightBackend\Validation\IpValidator;
use Illuminate\Support\Facades\Log;

class SocketUdpTransport implements UdpTransport
{
    private string $broadcastAddress;
    private int $udpPort;

    private ?resource $socket = null;

    public function __construct(string $broadcastAddress = '255.255.255.255', int $udpPort = 38899)
    {
        $this->broadcastAddress = $broadcastAddress;
        $this->udpPort = $udpPort;
    }

    public function send(mixed $message, array $targets): void
    {
        $socket = $this->ensureSocket();
        $payload = json_encode($message);

        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);

        foreach ($targets as $destIp) {
            if ($destIp !== $this->broadcastAddress && !IpValidator::isPrivateIp($destIp)) {
                Log::warning("Skipping non-private IP in send_udp: {$destIp}");
                continue;
            }
            socket_sendto($socket, $payload, strlen($payload), 0, $destIp, $this->udpPort);
        }
    }

    public function receive(float $timeout): ?array
    {
        $socket = $this->ensureSocket();
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        $start = microtime(true);

        $results = [];
        while (true) {
            $buf = '';
            $from = '';
            $port = 0;
            $bytes = @socket_recvfrom($socket, $buf, 1024, 0, $from, $port);
            if ($bytes === false) break;
            if ($bytes > 0) {
                $data = json_decode($buf, true);
                $data['from'] = $from;
                $results[] = $data;
            }
            if (microtime(true) - $start > $timeout) break;
        }

        return $results ?: null;
    }

    public function close(): void
    {
        if ($this->socket) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    private function ensureSocket(): resource
    {
        if ($this->socket === null) {
            $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        }
        return $this->socket;
    }

    public function __destruct()
    {
        $this->close();
    }
}
