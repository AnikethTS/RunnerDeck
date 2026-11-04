<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testNonceIsStableForTheRequest(): void
    {
        $first = \RunnerDeck\Security\SecurityHeaders::nonce();
        $second = \RunnerDeck\Security\SecurityHeaders::nonce();

        $this->assertSame($first, $second);
        $this->assertNotSame('', $first);
        $this->assertStringContainsString($first, \RunnerDeck\Security\SecurityHeaders::nonceAttr());
    }

    #[RunInSeparateProcess]
    public function testSendSetsFrameReferrerAndCsp(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);
        $headers = implode("\n", \RunnerDeck\Security\SecurityHeaders::lines());

        $this->assertStringContainsString('X-Frame-Options: DENY', $headers);
        $this->assertStringContainsString('Referrer-Policy: no-referrer', $headers);
        $this->assertStringContainsString('Content-Security-Policy:', $headers);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $headers);
        $this->assertStringNotContainsString('Strict-Transport-Security', $headers);
    }

    #[RunInSeparateProcess]
    public function testLinesIncludeHstsOnHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $headers = implode("\n", \RunnerDeck\Security\SecurityHeaders::lines());
        $this->assertStringContainsString('Strict-Transport-Security:', $headers);
    }
}
