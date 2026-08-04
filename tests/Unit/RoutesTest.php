<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Controllers\BulbController;
use ClarionApp\WizlightBackend\Controllers\RoomController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/**
 * Route registration asserted against the registered router, not against the
 * text of Routes.php (FR-016). The predecessor of this file matched string
 * fragments, which pass whether or not the routes are ever registered.
 */
class RoutesTest extends TestCase
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
    }

    /**
     * @return array<string, \Illuminate\Routing\Route>
     */
    private function packageRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!str_contains($action, 'ClarionApp\\WizlightBackend')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $routes["{$method} " . $route->uri()] = $route;
            }
        }

        return $routes;
    }

    private function uriFor(string $controllerMethod): ?string
    {
        foreach ($this->packageRoutes() as $key => $route) {
            if (str_ends_with($route->getActionName(), $controllerMethod)) {
                return $key;
            }
        }

        return null;
    }

    /** @test */
    public function bulb_resource_is_registered_without_store_and_show()
    {
        $this->assertNotNull($this->uriFor(BulbController::class . '@index'));
        $this->assertNotNull($this->uriFor(BulbController::class . '@update'));
        $this->assertNotNull($this->uriFor(BulbController::class . '@destroy'));

        // The refused pair the frontend audit (FR-012) is anchored to.
        $this->assertNull($this->uriFor(BulbController::class . '@store'), 'POST /bulb must stay unrouted');
        $this->assertNull($this->uriFor(BulbController::class . '@show'), 'GET /bulb/{id} must stay unrouted');
    }

    /** @test */
    public function room_resource_is_fully_registered()
    {
        foreach (['index', 'store', 'show', 'update', 'destroy'] as $method) {
            $this->assertNotNull(
                $this->uriFor(RoomController::class . '@' . $method),
                "RoomController@{$method} should be routed"
            );
        }
    }

    /** @test */
    public function every_package_route_is_behind_auth_api()
    {
        $routes = $this->packageRoutes();
        $this->assertNotEmpty($routes, 'Precondition: the package registered routes');

        foreach ($routes as $key => $route) {
            $this->assertContains(
                'auth:api',
                $route->gatherMiddleware(),
                "{$key} is not behind auth:api (constitution §IV)"
            );
        }
    }

    /** @test */
    public function broadcast_channel_authorises_an_authenticated_user_and_refuses_a_guest()
    {
        // The channel name must match BulbStatusEvent::broadcastOn(), or the
        // browser subscribes to a channel nothing authorises.
        $callback = Broadcast::getChannels()['clarion-app-wizlights'] ?? null;
        $this->assertNotNull($callback, 'clarion-app-wizlights must be an authorised private channel');

        $this->assertTrue((bool) $callback((object) ['id' => 1]));
        $this->assertFalse((bool) $callback(null));
    }

    /** @test */
    public function room_controller_has_no_addBulb_or_removeBulb_methods()
    {
        $reflection = new \ReflectionClass(RoomController::class);
        $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        $this->assertNotContains('addBulb', $methods, 'RoomController should not contain addBulb method');
        $this->assertNotContains('removeBulb', $methods, 'RoomController should not contain removeBulb method');
    }
}
