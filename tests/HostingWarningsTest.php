<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HostingWarningsTest extends TestCase
{
    protected function tearDown(): void
    {
        \RunnerDeck\Core\HostingWarnings::fakeSapi(null);
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

        $this->assertFalse(\RunnerDeck\Core\HostingWarnings::isHostedRequest());
        $this->assertSame([], \RunnerDeck\Core\HostingWarnings::messages());
        $this->assertSame('', \RunnerDeck\Core\HostingWarnings::html());
    }

    #[RunInSeparateProcess]
    public function testQuietOnLocalhostHttpEvenWhenRemoteIsDockerBridge(): void
    {
        $_SERVER['HTTPS'] = '';
        $_SERVER['REMOTE_ADDR'] = '172.17.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost:8090';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);

        $this->assertSame([], \RunnerDeck\Core\HostingWarnings::messages());
    }

    #[RunInSeparateProcess]
    public function testWarnsOnPublicHostnameWithoutTls(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);

        $msgs = \RunnerDeck\Core\HostingWarnings::messages();
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

        $msgs = \RunnerDeck\Core\HostingWarnings::messages();
        $joined = implode("\n", array_column($msgs, 'text'));
        $this->assertStringContainsString('TOTP is the only gate', $joined);
        $this->assertStringNotContainsString('not HTTPS', $joined);
    }

    #[RunInSeparateProcess]
    public function testPhpBuiltInServerNoteOnHostedRequest(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        \RunnerDeck\Core\HostingWarnings::fakeSapi('cli-server');

        $msgs = \RunnerDeck\Core\HostingWarnings::messages();
        $joined = implode("\n", array_column($msgs, 'text'));
        $this->assertStringContainsString('php -S', $joined);
        $html = \RunnerDeck\Core\HostingWarnings::html();
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

        $joined = implode("\n", array_column(\RunnerDeck\Core\HostingWarnings::messages(), 'text'));
        $this->assertStringContainsString('TRUST_PROXY', $joined);
        $this->assertStringNotContainsString('not HTTPS', $joined);
    }

    #[RunInSeparateProcess]
    public function testSettingsCardAlwaysShowsLoopbackFacts(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);
        \RunnerDeck\Core\HostingWarnings::fakeSapi('cli-server');
        putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        putenv('RUNNERDECK_TRUST_PROXY');

        $facts = \RunnerDeck\Core\HostingWarnings::facts();
        $byLabel = [];
        foreach ($facts as $row) {
            $byLabel[$row['label']] = $row['value'];
        }
        $this->assertSame('127.0.0.1:8090', $byLabel['Host']);
        $this->assertSame('127.0.0.1', $byLabel['Bind']);
        $this->assertSame('No', $byLabel['HTTPS']);
        $this->assertSame('php -S', $byLabel['Server']);
        $this->assertSame('Off', $byLabel['TOTP']);
        $this->assertSame('Off', $byLabel['TRUST_PROXY']);

        $html = \RunnerDeck\Core\HostingWarnings::settingsCardHtml();
        $this->assertStringContainsString('id="this-host"', $html);
        $this->assertStringContainsString('This host', $html);
        $this->assertStringContainsString('127.0.0.1:8090', $html);
        $this->assertStringContainsString('php -S', $html);
        $this->assertStringContainsString('docs/hosting.md', $html);
    }

    #[RunInSeparateProcess]
    public function testSettingsCardEscapesHostAndShowsPhpFpm(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'ex.com<script>';
        \RunnerDeck\Core\HostingWarnings::fakeSapi('fpm-fcgi');

        $html = \RunnerDeck\Core\HostingWarnings::settingsCardHtml();
        $this->assertStringContainsString('ex.com&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('php-fpm', $html);
        $this->assertStringContainsString('<dd>Yes</dd>', $html);
    }

    #[RunInSeparateProcess]
    public function testRequestHostStripsPortAndIpv6Brackets(): void
    {
        $_SERVER['HTTP_HOST'] = 'Example.COM:443';
        $this->assertSame('example.com', \RunnerDeck\Core\HostingWarnings::requestHost());

        $_SERVER['HTTP_HOST'] = '[::1]:8090';
        $this->assertSame('::1', \RunnerDeck\Core\HostingWarnings::requestHost());
    }
}
