<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use Illuminate\Broadcasting\PrivateChannel;

class BulbStatusEventTest extends TestCase
{
    /** @test */
    public function event_carries_bulb_payload()
    {
        $bulb = ['id' => 'bulb-123', 'name' => 'Kitchen Light', 'state' => true];
        $event = new BulbStatusEvent($bulb);
        $this->assertEquals($bulb, $event->bulb);
    }

    /** @test */
    public function broadcastOn_returns_private_channel()
    {
        $event = new BulbStatusEvent(['id' => 'bulb-123']);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }

    /** @test */
    public function broadcastOn_uses_correct_channel_name()
    {
        $event = new BulbStatusEvent(['id' => 'bulb-123']);
        $channels = $event->broadcastOn();

        $this->assertEquals('private-clarion-app-wizlights', (string) $channels[0]);
    }

    /** @test */
    public function channel_name_matches_frontend_subscription()
    {
        $event = new BulbStatusEvent(['id' => 'bulb-123']);
        $channels = $event->broadcastOn();

        // Frontend subscribes via Echo.private('clarion-app-wizlights')
        // which maps to channel string 'private-clarion-app-wizlights'
        $this->assertEquals('private-clarion-app-wizlights', (string) $channels[0]);
    }
}
