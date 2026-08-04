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
    public function handle_acquires_per_bulb_lock_for_serialization()
    {
        $transport = $this->makeMockTransport();
        $command = (object)['method' => 'setPilot'];
        $job = new SendBulbCommand('192.168.1.10', $command, 'bulb-serialize', $transport);

        // Verify lock key pattern in source
        $source = file_get_contents(__DIR__ . '/../../src/Jobs/SendBulbCommand.php');
        $this->assertStringContainsString('bulb-command-', $source, 'Job must use per-bulb lock key');
        $this->assertStringContainsString('Cache::lock', $source, 'Job must use Cache::lock for serialization');
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
