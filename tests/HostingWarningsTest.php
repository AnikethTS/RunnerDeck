<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HostingWarningsTest extends TestCase
{
    protected function tearDown(): void
    {
        \RunnerDeck\HostingWarnings::fakeSapi(null);
        putenv('RUNNERDECK_TRUST_PROXY');
        $_SERVER = [];
    }

    #[RunInSeparateProcess]
    public function testQuietOnLoopbackHttp(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);

        $this->assertFalse(\RunnerDeck\HostingWarnings::isHostedRequest());
        $this->assertSame([], \RunnerDeck\HostingWarnings::messages());
        $this->assertSame('', \RunnerDeck\HostingWarnings::html());
    }

    #[RunInSeparateProcess]
    public function testQuietOnLocalhostHttpEvenWhenRemoteIsDockerBridge(): void
    {
        $_SERVER['HTTPS'] = '';
        $_SERVER['REMOTE_ADDR'] = '172.17.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost:8090';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);

        $this->assertSame([], \RunnerDeck\HostingWarnings::messages());
    }

    #[RunInSeparateProcess]
    public function testWarnsOnPublicHostnameWithoutTls(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);

        $msgs = \RunnerDeck\HostingWarnings::messages();
        $this->assertNotSame([], $msgs);
        $joined = implode("\n", array_column($msgs, 'text'));
        $this->assertStringContainsString('not HTTPS', $joined);
        $this->assertStringContainsString('TOTP is the only gate', $joined);
        $this->assertStringContainsString('docs/hosting.md', $joined);
    }

    #[RunInSeparateProcess]
    public function testHttpsHostnameWarnsTotpOnlyNotMissingTls(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';

        $msgs = \RunnerDeck\HostingWarnings::messages();
        $joined = implode("\n", array_column($msgs, 'text'));
        $this->assertStringContainsString('TOTP is the only gate', $joined);
        $this->assertStringNotContainsString('not HTTPS', $joined);
    }

    #[RunInSeparateProcess]
    public function testPhpBuiltInServerNoteOnHostedRequest(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        \RunnerDeck\HostingWarnings::fakeSapi('cli-server');

        $msgs = \RunnerDeck\HostingWarnings::messages();
        $joined = implode("\n", array_column($msgs, 'text'));
        $this->assertStringContainsString('php -S', $joined);
        $html = \RunnerDeck\HostingWarnings::html();
        $this->assertStringContainsString('banner-info', $html);
        $this->assertStringContainsString('id="hosting-warnings"', $html);
    }

    #[RunInSeparateProcess]
    public function testTrustProxyFromNonLoopback(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        putenv('RUNNERDECK_TRUST_PROXY=1');

        $joined = implode("\n", array_column(\RunnerDeck\HostingWarnings::messages(), 'text'));
        $this->assertStringContainsString('TRUST_PROXY', $joined);
        $this->assertStringNotContainsString('not HTTPS', $joined);
    }

    #[RunInSeparateProcess]
    public function testRequestHostStripsPortAndIpv6Brackets(): void
    {
        $_SERVER['HTTP_HOST'] = 'Example.COM:443';
        $this->assertSame('example.com', \RunnerDeck\HostingWarnings::requestHost());

        $_SERVER['HTTP_HOST'] = '[::1]:8090';
        $this->assertSame('::1', \RunnerDeck\HostingWarnings::requestHost());
    }
}
