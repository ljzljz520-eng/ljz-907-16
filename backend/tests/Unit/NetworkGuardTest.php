<?php

namespace Tests\Unit;

use App\Support\NetworkGuard;
use PHPUnit\Framework\TestCase;

class NetworkGuardTest extends TestCase
{
    public function test_blocks_loopback_and_private_addresses(): void
    {
        $this->assertFalse(NetworkGuard::isPublicHost('127.0.0.1'));
        $this->assertFalse(NetworkGuard::isPublicHost('10.0.0.5'));
        $this->assertFalse(NetworkGuard::isPublicHost('192.168.1.1'));
        $this->assertFalse(NetworkGuard::isPublicHost('172.16.4.4'));
        $this->assertFalse(NetworkGuard::isPublicHost('169.254.1.1'));
        $this->assertFalse(NetworkGuard::isPublicHost('0.0.0.0'));
        $this->assertFalse(NetworkGuard::isPublicHost('::1'));
        $this->assertFalse(NetworkGuard::isPublicHost('fc00::1'));
        $this->assertFalse(NetworkGuard::isPublicHost('localhost'));
    }

    public function test_allows_public_ip_literals_without_dns(): void
    {
        $this->assertTrue(NetworkGuard::isPublicHost('93.184.216.34'));
        $this->assertTrue(NetworkGuard::isPublicHost('8.8.8.8'));
    }

    public function test_validates_scheme_and_format(): void
    {
        $this->assertFalse(NetworkGuard::isPublicHttpUrl('ftp://93.184.216.34/a.png'));
        $this->assertFalse(NetworkGuard::isPublicHttpUrl('file:///etc/passwd'));
        $this->assertFalse(NetworkGuard::isPublicHttpUrl('http://127.0.0.1/x.png'));
        $this->assertFalse(NetworkGuard::isPublicHttpUrl('not-a-url'));
        $this->assertTrue(NetworkGuard::isPublicHttpUrl('https://93.184.216.34/a.png'));
    }
}
