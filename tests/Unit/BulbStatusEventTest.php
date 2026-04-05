<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BulbStatusEventTest extends TestCase
{
    /** @test */
    public function broadcastOn_returns_private_channel()
    {
        $eventSource = file_get_contents(__DIR__ . '/../../src/Events/BulbStatusEvent.php');

        $this->assertStringContainsString('PrivateChannel', $eventSource,
            'BulbStatusEvent must use PrivateChannel, not Channel');
        $this->assertStringContainsString('clarion-app-wizlights', $eventSource,
            'BulbStatusEvent must broadcast on clarion-app-wizlights channel');
    }

    /** @test */
    public function channel_name_is_clarion_app_wizlights()
    {
        $eventSource = file_get_contents(__DIR__ . '/../../src/Events/BulbStatusEvent.php');

        // Verify the channel name
        $this->assertStringContainsString("new PrivateChannel('clarion-app-wizlights')", $eventSource);
    }
}
