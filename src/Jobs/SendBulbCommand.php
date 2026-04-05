<?php

namespace ClarionApp\WizlightBackend\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ClarionApp\WizlightBackend\Wiz;
use Illuminate\Support\Facades\Log;

class SendBulbCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    private string $ip;
    private object $command;
    private string $bulbId;

    public function __construct(string $ip, object $command, string $bulbId)
    {
        $this->ip = $ip;
        $this->command = $command;
        $this->bulbId = $bulbId;
    }

    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(): void
    {
        $wiz = new Wiz();
        $wiz->send_udp($this->command, $this->ip);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendBulbCommand failed for bulb {$this->bulbId} at {$this->ip}: {$exception->getMessage()}");
    }
}
