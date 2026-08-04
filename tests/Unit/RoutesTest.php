<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Controllers\RoomController;

class RoutesTest extends TestCase
{
    /** @test */
    public function routes_file_registers_bulb_resource_without_store_and_show()
    {
        $routesContent = file_get_contents(__DIR__ . '/../../src/Routes.php');

        // Bulb resource route must be registered
        $this->assertStringContainsString("Route::resource('bulb'", $routesContent);
        // store and show must be excluded
        $this->assertStringContainsString("->except(['store', 'show'])", $routesContent);
    }

    /** @test */
    public function routes_file_registers_room_resource()
    {
        $routesContent = file_get_contents(__DIR__ . '/../../src/Routes.php');

        $this->assertStringContainsString("Route::resource('room'", $routesContent);
    }

    /** @test */
    public function routes_are_protected_by_auth_api_middleware()
    {
        $routesContent = file_get_contents(__DIR__ . '/../../src/Routes.php');

        $this->assertStringContainsString("'middleware'=>['auth:api']", $routesContent);
    }

    /** @test */
    public function broadcast_channel_is_registered_for_clarion_app_wizlights()
    {
        $routesContent = file_get_contents(__DIR__ . '/../../src/Routes.php');

        $this->assertStringContainsString("Broadcast::channel('clarion-app-wizlights'", $routesContent);
    }

    /** @test */
    public function room_controller_has_no_addBulb_or_removeBulb_methods()
    {
        $reflection = new \ReflectionClass(RoomController::class);
        $methods = array_map(fn($m) => $m->getName(), $reflection->getMethods());

        $this->assertNotContains('addBulb', $methods, 'RoomController should not contain addBulb method');
        $this->assertNotContains('removeBulb', $methods, 'RoomController should not contain removeBulb method');
    }
}
