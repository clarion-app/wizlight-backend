<?php

namespace ClarionApp\WizlightBackend\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class BulbCommandFailedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $bulbId;
    public array $lastKnownState;

    public function __construct(string $bulbId, array $lastKnownState = [])
    {
        $this->bulbId = $bulbId;
        $this->lastKnownState = $lastKnownState;
    }

    public function broadcastOn()
    {
        return [
            new PrivateChannel('clarion-app-wizlights'),
        ];
    }
}
