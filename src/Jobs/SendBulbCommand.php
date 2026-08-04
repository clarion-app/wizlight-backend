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
        $lock = Cache::lock("bulb-command-{$this->bulbId}", 10);
        $lock->block(5, function () {
            $transport = $this->transport ?? app(UdpTransport::class);
            $transport->send($this->command, [$this->ip]);
            $transport->close();
        });
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
