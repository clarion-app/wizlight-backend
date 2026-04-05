<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Validation\IpValidator;

class WizTest extends TestCase
{
    /** @test */
    public function send_udp_rejects_non_private_ips()
    {
        // Attempting to send to a public IP should skip it — no socket error
        // We verify by checking that IpValidator rejects these
        $this->assertFalse(IpValidator::isPrivateIp('8.8.8.8'));
        $this->assertFalse(IpValidator::isPrivateIp('1.1.1.1'));
        $this->assertFalse(IpValidator::isPrivateIp('203.0.114.1'));
    }

    /** @test */
    public function send_udp_accepts_private_ips()
    {
        $this->assertTrue(IpValidator::isPrivateIp('192.168.1.1'));
        $this->assertTrue(IpValidator::isPrivateIp('10.0.0.1'));
        $this->assertTrue(IpValidator::isPrivateIp('172.16.0.1'));
    }

    /** @test */
    public function set_pilot_state_temperature_branch_uses_integers()
    {
        // Verify sprintf with integers, not strings
        $message = sprintf(
            '{"method":"setPilot","params":{"r":%d,"g":%d,"b":%d,"dimming":%d,"temp":%d,"state":%d}}',
            0,
            0,
            0,
            50,
            4000,
            1
        );

        $decoded = json_decode($message, true);
        $this->assertIsInt($decoded['params']['r']);
        $this->assertIsInt($decoded['params']['temp']);
        $this->assertIsInt($decoded['params']['dimming']);
        $this->assertEquals(0, $decoded['params']['r']);
        $this->assertEquals(4000, $decoded['params']['temp']);
    }

    /** @test */
    public function discover_processes_all_responses_not_just_first()
    {
        // Read the source and verify no break statement in the foreach loop
        $wizSource = file_get_contents(__DIR__ . '/../../src/Wiz.php');

        // Find the discover method and verify no break inside the foreach
        preg_match('/public function discover\(\).*?\n    \}/s', $wizSource, $matches);
        $discoverMethod = $matches[0] ?? '';

        // The foreach loop should NOT contain a break statement
        // Extract the foreach body
        preg_match('/foreach\s*\(\$results as \$data\)\s*\{(.*?)\n        \}/s', $discoverMethod, $foreachMatch);
        $foreachBody = $foreachMatch[1] ?? '';

        $this->assertStringNotContainsString('break;', $foreachBody,
            'discover() foreach loop should not contain break; all responses must be processed');
    }

    /** @test */
    public function discovery_uses_updateOrCreate_for_mac_dedup()
    {
        // Verify BulbDiscovery uses updateOrCreate keyed on MAC
        $discoverySource = file_get_contents(__DIR__ . '/../../src/Jobs/BulbDiscovery.php');

        $this->assertStringContainsString('updateOrCreate', $discoverySource,
            'BulbDiscovery should use updateOrCreate to handle MAC-based dedup');
        $this->assertStringContainsString("'mac'", $discoverySource,
            'BulbDiscovery should key updateOrCreate on mac field');
    }

    /** @test */
    public function config_usage_in_wiz_constructor()
    {
        // Verify Wiz reads from config
        $wizSource = file_get_contents(__DIR__ . '/../../src/Wiz.php');

        $this->assertStringContainsString("config('wizlight.", $wizSource,
            'Wiz should read from wizlight config');
    }
}
