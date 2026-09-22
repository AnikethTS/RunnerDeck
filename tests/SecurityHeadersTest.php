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
        $first = \RunnerDeck\SecurityHeaders::nonce();
        $second = \RunnerDeck\SecurityHeaders::nonce();

        $this->assertSame($first, $second);
        $this->assertNotSame('', $first);
        $this->assertStringContainsString($first, \RunnerDeck\SecurityHeaders::nonceAttr());
    }

    #[RunInSeparateProcess]
    public function testSendSetsFrameReferrerAndCsp(): void
    {
        $headers = implode("\n", \RunnerDeck\SecurityHeaders::lines());

        $this->assertStringContainsString('X-Frame-Options: DENY', $headers);
        $this->assertStringContainsString('Referrer-Policy: no-referrer', $headers);
        $this->assertStringContainsString('Content-Security-Policy:', $headers);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $headers);
    }
}
