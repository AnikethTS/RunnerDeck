<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testBase32RoundTrips(): void
    {
        $secret = \RunnerDeck\Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function testKnownRfcVectorAtT59(): void
    {
        $this->assertSame('287082', \RunnerDeck\Totp::code(self::RFC_SECRET, 59));
    }

    public function testKnownRfcVectorAtT1111111109(): void
    {
        $this->assertSame('081804', \RunnerDeck\Totp::code(self::RFC_SECRET, 1111111109));
    }

    public function testVerifyAcceptsCurrentCode(): void
    {
        $this->assertTrue(\RunnerDeck\Totp::verify(self::RFC_SECRET, '287082', 59));
    }

    public function testVerifyAcceptsOneStepOfClockDrift(): void
    {
        $this->assertTrue(\RunnerDeck\Totp::verify(self::RFC_SECRET, '287082', 75));
    }

    public function testVerifyRejectsCodeOutsideDriftWindow(): void
    {
        $this->assertFalse(\RunnerDeck\Totp::verify(self::RFC_SECRET, '287082', 200));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $this->assertFalse(\RunnerDeck\Totp::verify(self::RFC_SECRET, '000000', 59));
    }
}
