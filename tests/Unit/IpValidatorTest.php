<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Validation\IpValidator;

class IpValidatorTest extends TestCase
{
    /** @test */
    public function it_accepts_10_x_private_ips()
    {
        $this->assertTrue(IpValidator::isPrivateIp('10.0.0.1'));
        $this->assertTrue(IpValidator::isPrivateIp('10.255.255.255'));
        $this->assertTrue(IpValidator::isPrivateIp('10.1.2.3'));
    }

    /** @test */
    public function it_accepts_172_16_31_private_ips()
    {
        $this->assertTrue(IpValidator::isPrivateIp('172.16.0.1'));
        $this->assertTrue(IpValidator::isPrivateIp('172.20.5.10'));
        $this->assertTrue(IpValidator::isPrivateIp('172.31.255.255'));
    }

    /** @test */
    public function it_accepts_192_168_private_ips()
    {
        $this->assertTrue(IpValidator::isPrivateIp('192.168.0.1'));
        $this->assertTrue(IpValidator::isPrivateIp('192.168.1.100'));
        $this->assertTrue(IpValidator::isPrivateIp('192.168.255.255'));
    }

    /** @test */
    public function it_rejects_public_ips()
    {
        $this->assertFalse(IpValidator::isPrivateIp('8.8.8.8'));
        $this->assertFalse(IpValidator::isPrivateIp('1.1.1.1'));
        $this->assertFalse(IpValidator::isPrivateIp('203.0.114.1'));
        $this->assertFalse(IpValidator::isPrivateIp('74.125.224.72'));
    }

    /** @test */
    public function it_rejects_loopback_ips()
    {
        $this->assertFalse(IpValidator::isPrivateIp('127.0.0.1'));
        $this->assertFalse(IpValidator::isPrivateIp('127.0.0.2'));
    }

    /** @test */
    public function it_rejects_link_local_ips()
    {
        $this->assertFalse(IpValidator::isPrivateIp('169.254.0.1'));
        $this->assertFalse(IpValidator::isPrivateIp('169.254.255.255'));
    }

    /** @test */
    public function it_rejects_documentation_range_ips()
    {
        $this->assertFalse(IpValidator::isPrivateIp('192.0.2.1'));
        $this->assertFalse(IpValidator::isPrivateIp('198.51.100.1'));
        $this->assertFalse(IpValidator::isPrivateIp('203.0.113.1'));
    }

    /** @test */
    public function it_rejects_invalid_ip_formats()
    {
        $this->assertFalse(IpValidator::isPrivateIp('not-an-ip'));
        $this->assertFalse(IpValidator::isPrivateIp(''));
        $this->assertFalse(IpValidator::isPrivateIp('256.1.1.1'));
        $this->assertFalse(IpValidator::isPrivateIp('10.0.0'));
        $this->assertFalse(IpValidator::isPrivateIp('::1'));
    }

    /** @test */
    public function it_rejects_172_outside_private_range()
    {
        $this->assertFalse(IpValidator::isPrivateIp('172.15.0.1'));
        $this->assertFalse(IpValidator::isPrivateIp('172.32.0.1'));
    }
}
