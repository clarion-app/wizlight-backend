<?php

namespace ClarionApp\WizlightBackend\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use ClarionApp\WizlightBackend\Transport\UdpTransport;

class CheckBulbStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public string $bulbId;
    private ?UdpTransport $transport;

    public function __construct(string $bulbId, ?UdpTransport $transport = null)
    {
        $this->bulbId = $bulbId;
        $this->transport = $transport;
    }

    public function handle(): ?array
    {
        $bulb = Bulb::find($this->bulbId);
        if (!$bulb) {
            return null;
        }

        $wiz = new Wiz(
            wait_time: 2.0,
            transport: $this->transport ?? app(UdpTransport::class)
        );

        $results = $wiz->get_pilot_state($bulb->ip);

        if ($results) {
            event(new BulbStatusEvent($bulb->fresh()));
        }

        return $results;
    }
}
