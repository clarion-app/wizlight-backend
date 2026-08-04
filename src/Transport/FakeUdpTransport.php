<?php

namespace ClarionApp\WizlightBackend\Transport;

/**
 * In-memory {@see UdpTransport} for tests (FR-014).
 *
 * Shipped with the package rather than re-improvised per test file, so every
 * phase has one place to script device behaviour, and so the fake itself can be
 * held to the interface's contract alongside {@see SocketUdpTransport}.
 *
 *     $fake = new FakeUdpTransport();
 *     $fake->willRespond(['result' => ['state' => true, 'rssi' => -60]]);
 *     $wiz = new Wiz(transport: $fake);
 */
class FakeUdpTransport implements UdpTransport
{
    /** @var array<int, ?array> queued receive() results, one per call */
    private array $scriptedResponses = [];

    /** @var array<int, array{message: mixed, targets: array}> */
    private array $sends = [];

    private int $receiveCount = 0;
    private int $closeCount = 0;

    /** @var array<int, float> the timeout passed to each receive() call */
    private array $receiveTimeouts = [];

    /**
     * Queue one receive() result made up of the given datagrams.
     *
     * Each datagram is an already-decoded response body; a `from` key is added
     * when absent so callers see the same shape the socket transport produces.
     */
    public function willRespond(array ...$datagrams): static
    {
        $normalised = [];
        foreach ($datagrams as $datagram) {
            if (!array_key_exists('from', $datagram)) {
                $datagram['from'] = '192.168.1.1';
            }
            $normalised[] = $datagram;
        }

        $this->scriptedResponses[] = $normalised ?: null;

        return $this;
    }

    /**
     * Queue one receive() result representing a timeout with no replies.
     */
    public function willRespondWithNothing(): static
    {
        $this->scriptedResponses[] = null;

        return $this;
    }

    public function send(mixed $message, array $targets): void
    {
        $this->sends[] = ['message' => $message, 'targets' => $targets];
    }

    public function receive(float $timeout): ?array
    {
        $this->receiveCount++;
        $this->receiveTimeouts[] = $timeout;

        if ($this->scriptedResponses === []) {
            return null;
        }

        return array_shift($this->scriptedResponses);
    }

    public function close(): void
    {
        $this->closeCount++;
    }

    /**
     * @return array<int, array{message: mixed, targets: array}>
     */
    public function sends(): array
    {
        return $this->sends;
    }

    public function sendCount(): int
    {
        return count($this->sends);
    }

    /**
     * Number of sends whose message carried the given protocol method —
     * how SC-012 counts round trips per light.
     */
    public function sendCountForMethod(string $method): int
    {
        $count = 0;
        foreach ($this->sends as $send) {
            $message = $send['message'];
            $sentMethod = is_object($message)
                ? ($message->method ?? null)
                : ($message['method'] ?? null);
            if ($sentMethod === $method) {
                $count++;
            }
        }

        return $count;
    }

    public function receiveCount(): int
    {
        return $this->receiveCount;
    }

    /**
     * @return array<int, float>
     */
    public function receiveTimeouts(): array
    {
        return $this->receiveTimeouts;
    }

    public function closeCount(): int
    {
        return $this->closeCount;
    }
}
