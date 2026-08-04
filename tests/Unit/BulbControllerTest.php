<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Controllers\BulbController;
use ClarionApp\WizlightBackend\Services\WizlightService;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\BulbLastSeen;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

class BulbControllerTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \ClarionApp\WizlightBackend\WizlightBackendServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('clarion.node_id', 'test-node-id');
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/Migrations');
    }

    private function createBulb(array $attrs = []): Bulb
    {
        $defaults = [
            'mac' => 'aa:bb:cc:dd:ee:01',
            'ip' => '192.168.1.10',
            'name' => 'Test Bulb',
            'state' => false,
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'dimming' => 50,
            'temperature' => 2700,
            'local_node_id' => 'other-node',
        ];
        $data = array_merge($defaults, $attrs);
        return Bulb::create($data);
    }

    private function makeController(): BulbController
    {
        Bus::fake();
        Event::fake();
        return new BulbController(new WizlightService());
    }

    /** @test */
    public function update_returns_immediately_with_updated_state()
    {
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'test-node-id',
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $result = $controller->update($request, (string) $bulb->id);

        $this->assertTrue($result->state);
        Bus::assertDispatched(SendBulbCommand::class);
        Event::assertDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function update_dispatches_command_for_local_node_bulb()
    {
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.10',
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $controller->update($request, (string) $bulb->id);

        Bus::assertDispatched(SendBulbCommand::class, function ($job) use ($bulb) {
            return $job->bulbId === (string) $bulb->id && $job->ip === '192.168.1.10';
        });
    }

    /** @test */
    public function update_skips_dispatch_for_remote_node_bulb()
    {
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'other-node',
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $controller->update($request, (string) $bulb->id);

        Bus::assertNotDispatched(SendBulbCommand::class);
    }

    /** @test */
    public function update_fires_bulb_status_event_on_change()
    {
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'test-node-id',
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $controller->update($request, (string) $bulb->id);

        Event::assertDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function update_no_dispatch_when_state_unchanged()
    {
        $bulb = $this->createBulb([
            'state' => false,
            'local_node_id' => 'test-node-id',
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => false,
        ]);

        $controller->update($request, (string) $bulb->id);

        Bus::assertNotDispatched(SendBulbCommand::class);
    }

    /** @test */
    public function update_preserves_dimming_when_omitted()
    {
        $bulb = $this->createBulb([
            'dimming' => 50,
            'red' => 255,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'red' => 100,
        ]);

        $result = $controller->update($request, (string) $bulb->id);

        $this->assertEquals(50, $result->dimming);
    }

    /** @test */
    public function update_sets_dimming_when_explicitly_provided()
    {
        $bulb = $this->createBulb(['dimming' => 50]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'dimming' => 75,
        ]);

        $result = $controller->update($request, (string) $bulb->id);

        $this->assertEquals(75, $result->dimming);
    }

    /** @test */
    public function update_returns_404_when_bulb_not_found()
    {
        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'state' => true,
        ]);

        $response = $controller->update($request, 'nonexistent-id');

        $this->assertEquals(404, $response->status());
    }

    /** @test */
    public function destroy_cascades_deletion_to_bulb_last_seen()
    {
        $bulb = $this->createBulb();
        $lastSeen = BulbLastSeen::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'bulb_id' => $bulb->id,
            'last_seen_at' => now(),
        ]);

        $bulb_id = (string) $bulb->id;
        $last_seen_id = (string) $lastSeen->id;

        $controller = $this->makeController();
        $response = $controller->destroy($bulb_id);

        $this->assertEquals(200, $response->status());
        $this->assertNull(Bulb::withTrashed()->find($bulb_id));
        $this->assertNull(BulbLastSeen::find($last_seen_id));
        $this->assertEquals(0, BulbLastSeen::where('bulb_id', $bulb_id)->count());
    }

    /** @test */
    public function destroy_returns_404_when_bulb_not_found()
    {
        $controller = $this->makeController();
        $response = $controller->destroy('nonexistent-id');

        $this->assertEquals(404, $response->status());
    }
}
