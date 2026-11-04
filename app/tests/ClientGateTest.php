<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ClientGateTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('RUNNERDECK_IP_ALLOWLIST');
        putenv('RUNNERDECK_REQUIRE_MTLS');
        putenv('RUNNERDECK_TRUST_PROXY');
        \RunnerDeck\Security\ClientGate::$denyHandler = null;
        $_SERVER = [];
    }

    #[RunInSeparateProcess]
    public function testLoopbackHostSkipsEvenFromDockerBridge(): void
    {
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
        $_SERVER['REMOTE_ADDR'] = '172.17.0.1';
        $this->assertTrue(\RunnerDeck\Security\ClientGate::skipExtraGate());
        $this->assertFalse(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testLocalhostHostSkips(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost:8090';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertFalse(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testLoopbackRemoteSkipsPublicHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertFalse(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testTailscaleCgnatSkips(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '100.64.1.20';
        $this->assertFalse(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testIpv6UlaSkips(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = 'fd12:3456::1';
        $this->assertFalse(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testPublicHostWithoutGateIsBlocked(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertTrue(\RunnerDeck\Security\ClientGate::blocked());
        $this->assertFalse(\RunnerDeck\Security\ClientGate::extraGateOk());
    }

    #[RunInSeparateProcess]
    public function testDockerBridgeWithPublicHostIsBlocked(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '172.17.0.1';
        $this->assertTrue(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testAllowlistHit(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        putenv('RUNNERDECK_IP_ALLOWLIST=203.0.113.0/24');
        $this->assertTrue(\RunnerDeck\Security\ClientGate::allowlistMatch());
        $this->assertFalse(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testAllowlistMiss(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
        putenv('RUNNERDECK_IP_ALLOWLIST=203.0.113.0/24');
        $this->assertFalse(\RunnerDeck\Security\ClientGate::allowlistMatch());
        $this->assertTrue(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testTrustProxyOffIgnoresSpoofedXff(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
        putenv('RUNNERDECK_IP_ALLOWLIST=203.0.113.9');
        putenv('RUNNERDECK_TRUST_PROXY');
        $this->assertFalse(\RunnerDeck\Security\ClientGate::allowlistMatch());
        $this->assertTrue(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testTrustProxyOnUsesLeftmostXff(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 198.51.100.4';
        putenv('RUNNERDECK_IP_ALLOWLIST=203.0.113.9');
        putenv('RUNNERDECK_TRUST_PROXY=1');
        $this->assertTrue(\RunnerDeck\Security\ClientGate::allowlistMatch());
        $this->assertFalse(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testMtlsRequiresSuccessHeader(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        putenv('RUNNERDECK_REQUIRE_MTLS=1');
        $this->assertTrue(\RunnerDeck\Security\ClientGate::blocked());

        $_SERVER['SSL_CLIENT_VERIFY'] = 'SUCCESS';
        $this->assertTrue(\RunnerDeck\Security\ClientGate::mtlsVerified());
        $this->assertFalse(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testMtlsOffIgnoresSuccessHeader(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['SSL_CLIENT_VERIFY'] = 'SUCCESS';
        $this->assertTrue(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testTrustProxyPlusTlsIsNotABypass(): void
    {
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTPS'] = 'on';
        putenv('RUNNERDECK_TRUST_PROXY=1');
        $this->assertTrue(\RunnerDeck\Security\ClientGate::blocked());
    }

    #[RunInSeparateProcess]
    public function testHealthAndMetricsDoNotCallClientGate(): void
    {
        $root = dirname(__DIR__);
        $health = (string) file_get_contents($root . '/public/health.php');
        $metrics = (string) file_get_contents($root . '/public/metrics.php');
        $login = (string) file_get_contents($root . '/public/login.php');
        $this->assertStringNotContainsString('ClientGate', $health);
        $this->assertStringNotContainsString('Auth::', $health);
        $this->assertStringNotContainsString('ClientGate', $metrics);
        $this->assertStringNotContainsString('Auth::', $metrics);
        $this->assertStringContainsString('ClientGate::enforce', $login);
    }

    #[RunInSeparateProcess]
    public function testParseIpAllowlistRejectsGarbage(): void
    {
        $this->assertSame([], \RunnerDeck\Configuration\Config::parseIpAllowlist(''));
        $this->assertSame(['203.0.113.9', '192.168.0.0/16'], \RunnerDeck\Configuration\Config::parseIpAllowlist(
            "203.0.113.9\n192.168.0.0/16"
        ));
        $this->assertNull(\RunnerDeck\Configuration\Config::parseIpAllowlist('not-an-ip'));
    }
}
