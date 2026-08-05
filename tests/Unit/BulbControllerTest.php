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
            // Pre-Phase-4 tests in this file predate capability awareness and
            // exercise unrestricted state/colour/dimming changes — default to
            // full_colour so they keep testing what they always tested. Tests
            // that care about capability gating override this explicitly.
            'capability_class' => 'full_colour',
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
        BulbLastSeen::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'bulb_id' => $bulb->id,
            'last_seen_at' => now(),
        ]);

        $bulb_id = (string) $bulb->id;

        // Deliberately no Event::fake() here: the cascade is a model `deleting`
        // listener, and faking events replaces the dispatcher it is registered
        // on, which would make this test pass or fail for the wrong reason.
        Bus::fake();
        $controller = new BulbController(new WizlightService());
        $response = $controller->destroy($bulb_id);

        $this->assertEquals(200, $response->status());
        $this->assertNull(Bulb::withTrashed()->find($bulb_id));
        $this->assertEquals(0, BulbLastSeen::where('bulb_id', $bulb_id)->count());
    }

    /** @test */
    public function deleting_a_bulb_outside_the_controller_still_cascades()
    {
        // The case that distinguishes a model-level rule from a controller-level
        // one (T052): a console command, a bulk delete, or a relationship
        // cascade never goes through BulbController::destroy().
        $bulb = $this->createBulb(['mac' => 'aa:bb:cc:dd:ee:99']);
        BulbLastSeen::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'bulb_id' => $bulb->id,
            'last_seen_at' => now(),
        ]);

        $bulb_id = (string) $bulb->id;
        $bulb->forceDelete();

        $this->assertEquals(0, BulbLastSeen::where('bulb_id', $bulb_id)->count());
    }

    /** @test */
    public function soft_deleting_a_bulb_also_cascades()
    {
        // A database ON DELETE CASCADE cannot see a soft delete — it is an
        // UPDATE — which is why the application-level hook is the primary
        // mechanism (FR-011) and the foreign key is only defence in depth.
        $bulb = $this->createBulb(['mac' => 'aa:bb:cc:dd:ee:98']);
        BulbLastSeen::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'bulb_id' => $bulb->id,
            'last_seen_at' => now(),
        ]);

        $bulb_id = (string) $bulb->id;
        $bulb->delete();

        $this->assertNotNull(Bulb::withTrashed()->find($bulb_id), 'Soft delete keeps the bulb row');
        $this->assertEquals(0, BulbLastSeen::where('bulb_id', $bulb_id)->count());
    }

    /** @test */
    public function destroy_returns_404_when_bulb_not_found()
    {
        $controller = $this->makeController();
        $response = $controller->destroy('nonexistent-id');

        $this->assertEquals(404, $response->status());
    }

    // ------------------------------------------------------------------
    // Phase 4 (US2): Device capability validation in controller
    // ------------------------------------------------------------------

    /** @test */
    public function update_rejects_colour_on_dim_only_bulb()
    {
        $bulb = $this->createBulb([
            'capability_class' => 'dim_only',
            'local_node_id' => 'test-node-id',
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'red' => 255,
            'green' => 0,
            'blue' => 0,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            $controller->update($request, (string) $bulb->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('red', $errors);
            $this->assertArrayHasKey('green', $errors);
            $this->assertArrayHasKey('blue', $errors);
            throw $e;
        }
    }

    /** @test */
    public function update_rejects_temperature_on_dim_only_bulb()
    {
        $bulb = $this->createBulb([
            'capability_class' => 'dim_only',
            'local_node_id' => 'test-node-id',
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'temperature' => 3000,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            $controller->update($request, (string) $bulb->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('temperature', $errors);
            throw $e;
        }
    }

    /** @test */
    public function update_rejects_temperature_out_of_range()
    {
        $bulb = $this->createBulb([
            'capability_class' => 'tunable_white',
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
            'local_node_id' => 'test-node-id',
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'temperature' => 1800,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            $controller->update($request, (string) $bulb->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('temperature', $errors);
            throw $e;
        }
    }

    /** @test */
    public function update_rejection_prevents_service_call_and_dispatch()
    {
        $bulb = $this->createBulb([
            'capability_class' => 'dim_only',
            'local_node_id' => 'test-node-id',
            'ip' => '192.168.1.10',
            'state' => false,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'red' => 255,
            'green' => 0,
            'blue' => 0,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            $controller->update($request, (string) $bulb->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Because the request is rejected at the validation layer,
            // no command should be dispatched and no event should fire.
            Bus::assertNotDispatched(SendBulbCommand::class);
            Event::assertNotDispatched(BulbStatusEvent::class);
            throw $e;
        }
    }

    /** @test */
    public function update_accepts_dimming_zero_on_any_bulb()
    {
        $bulb = $this->createBulb([
            'capability_class' => 'dim_only',
            'min_brightness_pct' => 5,
            'local_node_id' => 'test-node-id',
            'dimming' => 50,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'dimming' => 0,
        ]);

        $result = $controller->update($request, (string) $bulb->id);

        $this->assertEquals(0, $result->dimming);
        Bus::assertDispatched(SendBulbCommand::class);
        Event::assertDispatched(BulbStatusEvent::class);
    }

    /** @test */
    public function update_accepts_dim_on_dim_only_bulb()
    {
        $bulb = $this->createBulb([
            'capability_class' => 'dim_only',
            'local_node_id' => 'test-node-id',
            'dimming' => 100,
        ]);

        $controller = $this->makeController();

        $request = Request::create('/', 'PUT', [
            'dimming' => 50,
        ]);

        $result = $controller->update($request, (string) $bulb->id);

        $this->assertEquals(50, $result->dimming);
        Bus::assertDispatched(SendBulbCommand::class);
    }
}
