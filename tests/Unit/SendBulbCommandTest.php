<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;

class SendBulbCommandTest extends TestCase
{
    /** @test */
    public function job_accepts_bulb_ip_and_command_payload()
    {
        $command = new \stdClass();
        $command->method = 'setPilot';
        $command->params = new \stdClass();
        $command->params->r = 255;

        $job = new SendBulbCommand('192.168.1.10', $command, 'test-bulb-id');

        $this->assertInstanceOf(SendBulbCommand::class, $job);
    }

    /** @test */
    public function backoff_returns_expected_delays()
    {
        $command = new \stdClass();
        $job = new SendBulbCommand('192.168.1.10', $command, 'test-bulb-id');

        $this->assertEquals([2, 10, 30], $job->backoff());
    }

    /** @test */
    public function tries_is_set_to_three()
    {
        $command = new \stdClass();
        $job = new SendBulbCommand('192.168.1.10', $command, 'test-bulb-id');

        $this->assertEquals(3, $job->tries);
    }
}
