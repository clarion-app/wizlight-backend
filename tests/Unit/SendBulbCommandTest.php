<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\Events\BulbCommandFailedEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class SendBulbCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \ClarionApp\WizlightBackend\WizlightBackendServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('cache.stores.testing', [
            'driver' => 'array',
        ]);
        $app['config']->set('cache.default', 'testing');
    }

    private function makeMockTransport(?callable $sendCallback = null): UdpTransport
    {
        $mock = $this->createMock(UdpTransport::class);
        if ($sendCallback) {
            $mock->method('send')->willReturnCallback($sendCallback);
        } else {
            $mock->method('send');
        }
        $mock->method('close');
        return $mock;
    }

    /** @test */
    public function job_accepts_bulb_ip_command_and_bulb_id()
    {
        $command = (object)['method' => 'setPilot'];
        $job = new SendBulbCommand('192.168.1.10', $command, 'test-bulb-id');

        $this->assertInstanceOf(SendBulbCommand::class, $job);
    }

    /** @test */
    public function backoff_returns_expected_delays()
    {
        $command = (object)['method' => 'setPilot'];
        $job = new SendBulbCommand('192.168.1.10', $command, 'test-bulb-id');

        $this->assertEquals([2, 10, 30], $job->backoff());
    }

    /** @test */
    public function tries_is_set_to_three()
    {
        $command = (object)['method' => 'setPilot'];
        $job = new SendBulbCommand('192.168.1.10', $command, 'test-bulb-id');

        $this->assertEquals(3, $job->tries);
    }

    /** @test */
    public function handle_sends_command_via_transport()
    {
        $sent = false;
        $transport = $this->makeMockTransport(
            function ($message, $targets) use (&$sent) {
                $sent = true;
                $this->assertEquals('setPilot', $message->method);
                $this->assertEquals(['192.168.1.10'], $targets);
            }
        );

        $command = (object)['method' => 'setPilot'];
        $job = new SendBulbCommand('192.168.1.10', $command, 'bulb-1', $transport);
        $job->handle();

        $this->assertTrue($sent, 'Transport send() was not called');
    }

    /** @test */
    public function handle_serializes_behind_the_per_bulb_lock()
    {
        // FR-002: the send happens inside this bulb's lock, so a concurrent
        // command to the same device cannot interleave with it.
        $lockFreeDuringSend = null;
        $transport = $this->makeMockTransport(function () use (&$lockFreeDuringSend) {
            $contender = Cache::lock('bulb-command-bulb-serialize', 10);
            $lockFreeDuringSend = $contender->get();
            if ($lockFreeDuringSend) {
                $contender->release();
            }
        });

        $job = new SendBulbCommand('192.168.1.10', (object) ['method' => 'setPilot'], 'bulb-serialize', $transport);
        $job->handle();

        $this->assertFalse($lockFreeDuringSend, 'The per-bulb lock must be held across the send');
    }

    /** @test */
    public function handle_does_not_wait_on_a_different_bulbs_lock()
    {
        // The lock must be keyed per device, or a room update to N lights
        // serializes behind one another.
        $transport = $this->makeMockTransport();
        $job = new SendBulbCommand('192.168.1.11', (object) ['method' => 'setPilot'], 'bulb-other', $transport);

        $lock = Cache::lock('bulb-command-bulb-serialize', 10);
        $lock->get();

        try {
            $job->handle();
        } finally {
            $lock->release();
        }

        $this->assertTrue(true, 'A command to a different bulb proceeds without waiting');
    }

    /** @test */
    public function consecutive_commands_to_one_bulb_are_paced_by_the_configured_interval()
    {
        // SC-011 / FR-002b, against a controlled clock rather than real waiting.
        // Note the case that must fail before the fix: both commands succeed, so
        // retry backoff never engages and never paces them.
        config(['wizlight.throttle.min_interval_ms' => 200]);

        $transport = $this->makeMockTransport();

        $first = new ClockedSendBulbCommand('192.168.1.10', (object) ['method' => 'setPilot'], 'bulb-paced', $transport);
        $first->fakeNowMs = 1_000_000;
        $first->handle();

        $second = new ClockedSendBulbCommand('192.168.1.10', (object) ['method' => 'setPilot'], 'bulb-paced', $transport);
        $second->fakeNowMs = 1_000_050; // 50 ms after the first send
        $second->handle();

        $this->assertEquals([150], $second->slept, 'The second command waits out the remaining 150ms');
        $this->assertEquals([], $first->slept, 'An isolated first command is not delayed');
    }

    /** @test */
    public function a_command_arriving_after_the_interval_is_not_delayed()
    {
        config(['wizlight.throttle.min_interval_ms' => 200]);

        $transport = $this->makeMockTransport();

        $first = new ClockedSendBulbCommand('192.168.1.10', (object) ['method' => 'setPilot'], 'bulb-spaced', $transport);
        $first->fakeNowMs = 2_000_000;
        $first->handle();

        $second = new ClockedSendBulbCommand('192.168.1.10', (object) ['method' => 'setPilot'], 'bulb-spaced', $transport);
        $second->fakeNowMs = 2_000_500;
        $second->handle();

        $this->assertEquals([], $second->slept);
    }

    /** @test */
    public function commands_to_different_bulbs_do_not_delay_each_other()
    {
        // The throttle is per device, never global: a room update fanning out to
        // N lights must not be serialized behind a shared pace (FR-002b/SC-011).
        config(['wizlight.throttle.min_interval_ms' => 200]);

        $transport = $this->makeMockTransport();
        $slept = [];

        foreach (['room-bulb-1', 'room-bulb-2', 'room-bulb-3', 'room-bulb-4', 'room-bulb-5'] as $i => $bulbId) {
            $job = new ClockedSendBulbCommand('192.168.1.' . (10 + $i), (object) ['method' => 'setPilot'], $bulbId, $transport);
            $job->fakeNowMs = 3_000_000; // the whole fan-out happens at one instant
            $job->handle();
            $slept = array_merge($slept, $job->slept);
        }

        $this->assertEquals([], $slept, 'A room fan-out to five lights waits for nothing');
    }

    /** @test */
    public function failed_fires_bulb_command_failed_event()
    {
        Event::fake();

        $transport = $this->makeMockTransport();
        $command = (object)['method' => 'setPilot'];
        $job = new SendBulbCommand('192.168.1.10', $command, 'bulb-fail', $transport);

        $job->failed(new \RuntimeException('Network error'));

        Event::assertDispatched(BulbCommandFailedEvent::class, function (BulbCommandFailedEvent $assertedEvent) {
            return $assertedEvent->bulbId === 'bulb-fail';
        });
    }

    /** @test */
    public function failed_event_includes_bulb_id()
    {
        Event::fake();

        $transport = $this->makeMockTransport();
        $command = (object)['method' => 'setPilot'];
        $job = new SendBulbCommand('192.168.1.10', $command, 'bulb-event-id', $transport);

        $job->failed(new \RuntimeException('Connection refused'));

        Event::assertDispatched(function (BulbCommandFailedEvent $event) {
            return $event->bulbId === 'bulb-event-id' && is_array($event->lastKnownState);
        });
    }

    /** @test */
    public function dispatch_via_bus_works()
    {
        Bus::fake();
        $command = (object)['method' => 'setPilot'];

        SendBulbCommand::dispatch('192.168.1.10', $command, 'bulb-dispatch');

        Bus::assertDispatched(SendBulbCommand::class, function ($job) {
            return $job->bulbId === 'bulb-dispatch';
        });
    }
}

/**
 * SendBulbCommand with its clock and its sleep replaced, so the throttle's
 * timing is asserted rather than waited out.
 */
class ClockedSendBulbCommand extends SendBulbCommand
{
    public int $fakeNowMs = 0;

    /** @var array<int, int> milliseconds slept, in order */
    public array $slept = [];

    protected function nowMs(): int
    {
        return $this->fakeNowMs;
    }

    protected function sleepMs(int $milliseconds): void
    {
        $this->slept[] = $milliseconds;
    }
}
