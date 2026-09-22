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
    public function testRequestIsHttpsTrustsForwardedProto(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertTrue(\RunnerDeck\Session::requestIsHttps());
    }

    #[RunInSeparateProcess]
    public function testRequestIsHttpsFalseOnPlainHttp(): void
    {
        $_SERVER['HTTPS'] = '';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);

        $this->assertFalse(\RunnerDeck\Session::requestIsHttps());
    }
}
