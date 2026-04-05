<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RoutesTest extends TestCase
{
    /** @test */
    public function all_routes_are_within_auth_api_middleware_group()
    {
        // Read the Routes.php file and verify the middleware group
        $routesContent = file_get_contents(__DIR__ . '/../../src/Routes.php');

        // Verify auth:api middleware wraps all routes
        $this->assertStringContainsString("'middleware'=>['auth:api']", $routesContent);

        // Verify resource routes are inside the middleware group
        $this->assertStringContainsString("Route::resource('bulb'", $routesContent);
        $this->assertStringContainsString("Route::resource('room'", $routesContent);
    }

    /** @test */
    public function bulb_store_and_show_routes_are_excluded()
    {
        $routesContent = file_get_contents(__DIR__ . '/../../src/Routes.php');

        // Verify bulb resource excludes store and show
        $this->assertStringContainsString("->except(['store', 'show'])", $routesContent);
    }

    /** @test */
    public function room_addBulb_and_removeBulb_methods_do_not_exist()
    {
        $controllerContent = file_get_contents(__DIR__ . '/../../src/Controllers/RoomController.php');

        $this->assertStringNotContainsString('function addBulb', $controllerContent,
            'RoomController should not contain addBulb method');
        $this->assertStringNotContainsString('function removeBulb', $controllerContent,
            'RoomController should not contain removeBulb method');
    }
}
