<?php

namespace ClarionApp\WizlightBackend\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\Events\BulbCommandFailedEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendBulbCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public string $ip;
    public object $command;
    public string $bulbId;
    private ?UdpTransport $transport;

    public function __construct(string $ip, object $command, string $bulbId, ?UdpTransport $transport = null)
    {
        $this->ip = $ip;
        $this->command = $command;
        $this->bulbId = $bulbId;
        $this->transport = $transport;
    }

    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(): void
    {
        // FR-002: the lock is keyed per bulb, so commands to one device are
        // ordered while commands to different devices proceed concurrently.
        $lock = Cache::lock("bulb-command-{$this->bulbId}", 10);
        $lock->block(5, function () {
            $this->pace();

            $transport = $this->transport ?? app(UdpTransport::class);
            $transport->send($this->command, [$this->ip]);
            $transport->close();

            Cache::put($this->throttleKey(), $this->nowMs(), 60);
        });
    }

    /**
     * FR-002b: hold consecutive sends to *this* device at least
     * `throttle.min_interval_ms` apart, because WiZ hardware silently drops
     * commands driven faster than a few per second.
     *
     * Keyed per bulb and run inside the per-bulb lock, so ordering and pacing
     * share one critical section and a room update to N lights still fans out
     * to all N at once. Distinct from backoff(), which only paces retries after
     * a failure — a room fan-out is commands that all succeed.
     */
    private function pace(): void
    {
        $minIntervalMs = (int) config('wizlight.throttle.min_interval_ms', 200);
        if ($minIntervalMs <= 0) {
            return;
        }

        $lastSentMs = Cache::get($this->throttleKey());
        if ($lastSentMs === null) {
            return;
        }

        $waitMs = $minIntervalMs - ($this->nowMs() - (int) $lastSentMs);
        if ($waitMs > 0) {
            $this->sleepMs($waitMs);
        }
    }

    private function throttleKey(): string
    {
        return "bulb-throttle-{$this->bulbId}";
    }

    /**
     * Seam for tests: the throttle's timing is asserted against a controlled
     * clock rather than by waiting out real milliseconds.
     */
    protected function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    protected function sleepMs(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendBulbCommand failed for bulb {$this->bulbId} at {$this->ip}: {$exception->getMessage()}");

        $lastKnownState = [];
        try {
            $bulb = \ClarionApp\WizlightBackend\Models\Bulb::find($this->bulbId);
            if ($bulb) {
                $lastKnownState = [
                    'state' => $bulb->state,
                    'dimming' => $bulb->dimming,
                    'red' => $bulb->red,
                    'green' => $bulb->green,
                    'blue' => $bulb->blue,
                ];
            }
        } catch (\Throwable $e) {
            // DB may not be available in all contexts; lastKnownState stays empty
        }

        event(new BulbCommandFailedEvent($this->bulbId, $lastKnownState));
    }
}
