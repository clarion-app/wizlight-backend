<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Validation\IpValidator;

class BulbDiscoveryTest extends TestCase
{
    /** @test */
    public function discovery_discards_responses_with_public_ips()
    {
        // Discovery should validate IPs before storing bulbs
        // A public IP should be rejected by IpValidator
        $this->assertFalse(IpValidator::isPrivateIp('8.8.8.8'));
        $this->assertFalse(IpValidator::isPrivateIp('203.0.113.1'));
        $this->assertFalse(IpValidator::isPrivateIp('74.125.224.72'));
    }

    /** @test */
    public function discovery_accepts_responses_with_private_ips()
    {
        $this->assertTrue(IpValidator::isPrivateIp('192.168.1.100'));
        $this->assertTrue(IpValidator::isPrivateIp('10.0.0.50'));
        $this->assertTrue(IpValidator::isPrivateIp('172.20.0.1'));
    }
}
