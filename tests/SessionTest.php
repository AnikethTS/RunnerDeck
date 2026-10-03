<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testStartSetsHttpOnlyAndSameSite(): void
    {
        $_SERVER['HTTPS'] = 'off';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);
        \RunnerDeck\Session::start();
        $params = session_get_cookie_params();

        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertFalse($params['secure']);
    }

    #[RunInSeparateProcess]
    public function testStartSetsSecureOnHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        \RunnerDeck\Session::start();
        $params = session_get_cookie_params();

        $this->assertTrue($params['secure']);
    }

    #[RunInSeparateProcess]
    public function testRequestIsHttpsTrustsForwardedProtoFromLoopback(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertTrue(\RunnerDeck\Session::requestIsHttps());
    }

    #[RunInSeparateProcess]
    public function testRequestIsHttpsIgnoresForwardedProtoFromUntrustedClient(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        putenv('RUNNERDECK_TRUST_PROXY');

        $this->assertFalse(\RunnerDeck\Session::requestIsHttps());
    }

    #[RunInSeparateProcess]
    public function testRequestIsHttpsTrustsForwardedProtoWhenTrustProxySet(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        putenv('RUNNERDECK_TRUST_PROXY=1');

        $this->assertTrue(\RunnerDeck\Session::requestIsHttps());
    }

    #[RunInSeparateProcess]
    public function testRequestIsHttpsFalseOnPlainHttp(): void
    {
        $_SERVER['HTTPS'] = '';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);

        $this->assertFalse(\RunnerDeck\Session::requestIsHttps());
    }

    #[RunInSeparateProcess]
    public function testRemoteIsLoopback(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertTrue(\RunnerDeck\Session::remoteIsLoopback());
        $_SERVER['REMOTE_ADDR'] = '::1';
        $this->assertTrue(\RunnerDeck\Session::remoteIsLoopback());
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertFalse(\RunnerDeck\Session::remoteIsLoopback());
    }
}
