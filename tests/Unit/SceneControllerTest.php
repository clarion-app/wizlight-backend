<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Controllers\SceneController;
use ClarionApp\WizlightBackend\Scenes\SceneCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class SceneControllerTest extends TestCase
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
        $app['config']->set('eloquent-multichain-bridge.disabled', true);

        // Configure a stub 'api' auth guard for route-level auth tests.
        $app['config']->set('auth.guards.api', [
            'driver' => 'token',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \Illuminate\Foundation\Auth\User::class,
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../src/Migrations');

        // Create users table for auth guard (minimal schema).
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('api_token', 80)->nullable()->unique();
        });
    }

    /** @test */
    public function index_returns_all_37_entries()
    {
        $controller = new SceneController();
        $request = Request::create('/', 'GET');

        $result = $controller->index($request);
        $data = json_decode($result->getContent(), true);

        $this->assertCount(37, $data);
    }

    /** @test */
    public function index_returns_correct_shape_per_entry()
    {
        $controller = new SceneController();
        $request = Request::create('/', 'GET');

        $result = $controller->index($request);
        $data = json_decode($result->getContent(), true);

        // Each entry must have id, name, animated, classes keys.
        foreach ($data as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('animated', $entry);
            $this->assertArrayHasKey('classes', $entry);
            $this->assertIsInt($entry['id']);
            $this->assertIsString($entry['name']);
            $this->assertIsBool($entry['animated']);
            $this->assertIsArray($entry['classes']);
        }
    }

    /** @test */
    public function index_returns_entries_in_id_order()
    {
        $controller = new SceneController();
        $request = Request::create('/', 'GET');

        $result = $controller->index($request);
        $data = json_decode($result->getContent(), true);

        $ids = array_column($data, 'id');
        $sortedIds = $ids;
        sort($sortedIds);

        $this->assertSame($sortedIds, $ids, 'Entries should be returned in ascending ID order');
    }

    /** @test */
    public function index_includes_first_entry_matching_catalogue()
    {
        $controller = new SceneController();
        $request = Request::create('/', 'GET');

        $result = $controller->index($request);
        $data = json_decode($result->getContent(), true);

        // Ocean should be the first entry (ID 1).
        $ocean = current(array_filter($data, fn ($e) => $e['id'] === 1));
        $this->assertNotEmpty($ocean);
        $this->assertSame('Ocean', $ocean['name']);
        $this->assertTrue($ocean['animated']);
        $this->assertContains('full_colour', $ocean['classes']);
    }

    /** @test */
    public function index_includes_dim_to_warm_as_last_entry()
    {
        $controller = new SceneController();
        $request = Request::create('/', 'GET');

        $result = $controller->index($request);
        $data = json_decode($result->getContent(), true);

        // Dim-to-warm (ID 40) should be the last entry.
        $dimToWarm = current(array_filter($data, fn ($e) => $e['id'] === 40));
        $this->assertNotEmpty($dimToWarm);
        $this->assertSame('Dim-to-warm', $dimToWarm['name']);
        $this->assertFalse($dimToWarm['animated']);
        $this->assertContains('tunable_white', $dimToWarm['classes']);
    }

    /** @test */
    public function index_contains_no_custom_mode_ids()
    {
        $controller = new SceneController();
        $request = Request::create('/', 'GET');

        $result = $controller->index($request);
        $data = json_decode($result->getContent(), true);

        $ids = array_column($data, 'id');

        // Custom modes 256-265 should not be present.
        foreach (range(256, 265) as $id) {
            $this->assertNotContains($id, $ids, "Custom mode ID {$id} should not be in the catalogue");
        }

        // Rhythm 1000 should not be present.
        $this->assertNotContains(1000, $ids, 'Rhythm ID 1000 should not be in the catalogue');
    }

    /** @test */
    public function index_contains_wake_up_with_all_three_classes()
    {
        // Wake-up (9) is available to all three capability classes.
        $controller = new SceneController();
        $request = Request::create('/', 'GET');

        $result = $controller->index($request);
        $data = json_decode($result->getContent(), true);

        $wakeUp = current(array_filter($data, fn ($e) => $e['id'] === 9));
        $this->assertNotEmpty($wakeUp);
        $this->assertSame('Wake-up', $wakeUp['name']);
        $this->assertTrue($wakeUp['animated']);
        $this->assertContains('full_colour', $wakeUp['classes']);
        $this->assertContains('tunable_white', $wakeUp['classes']);
        $this->assertContains('dim_only', $wakeUp['classes']);
    }

    /** @test */
    public function unauthenticated_request_is_rejected()
    {
        // The route is registered inside the auth:api middleware group.
        // We test this by hitting the route directly through the test harness.
        // Without authentication, the AuthenticationException is converted
        // to a 401 response by the exception handler.
        $response = $this->json('GET', '/api/clarion-app/wizlights/scene');

        // Without authentication, the request should be rejected (401).
        $this->assertSame(401, $response->status());
    }
}
