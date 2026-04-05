<?php

namespace ClarionApp\WizlightBackend\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class BulbStatusEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $bulb;

    public function __construct($bulb)
    {
        $this->bulb = $bulb;
    }

    public function broadcastOn()
    {
        return [
            new PrivateChannel('clarion-app-wizlights'),
        ];
    }
}