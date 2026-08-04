<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Events\BulbCommandFailedEvent;
use Illuminate\Broadcasting\PrivateChannel;

class BulbCommandFailedEventTest extends TestCase
{
    /** @test */
    public function event_carries_bulb_id()
    {
        $event = new BulbCommandFailedEvent('bulb-123');
        $this->assertEquals('bulb-123', $event->bulbId);
    }

    /** @test */
    public function event_carries_last_known_state()
    {
        $state = ['state' => false, 'dimming' => 80, 'red' => 255, 'green' => 100, 'blue' => 50];
        $event = new BulbCommandFailedEvent('bulb-456', $state);
        $this->assertEquals($state, $event->lastKnownState);
    }

    /** @test */
    public function event_defaults_last_known_state_to_empty_array()
    {
        $event = new BulbCommandFailedEvent('bulb-789');
        $this->assertEquals([], $event->lastKnownState);
    }

    /** @test */
    public function broadcastOn_returns_private_channel()
    {
        $event = new BulbCommandFailedEvent('bulb-123');
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }

    /** @test */
    public function broadcastOn_uses_correct_channel_name()
    {
        $event = new BulbCommandFailedEvent('bulb-123');
        $channels = $event->broadcastOn();

        $this->assertEquals('private-clarion-app-wizlights', (string) $channels[0]);
    }

    /** @test */
    public function event_uses_same_channel_as_bulb_status_event()
    {
        $failedEvent = new BulbCommandFailedEvent('bulb-123');
        $failedChannels = $failedEvent->broadcastOn();

        // BulbStatusEvent broadcasts on clarion-app-wizlights private channel
        $this->assertEquals('private-clarion-app-wizlights', (string) $failedChannels[0]);
    }
}
