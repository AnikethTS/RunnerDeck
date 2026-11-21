<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Security\QrCode;
use RunnerDeck\Security\Totp;

final class QrCodeTest extends TestCase
{
    public function testHelloMatchesTheKnownMatrix(): void
    {
        $this->assertSame(
            '1193a98e9acd91c1a850105b67ec324d33ff4bfef4feb63262394e1ebfb46068',
            self::matrixHash('hello')
        );
        $this->assertCount(21, QrCode::modules('hello'));
    }

    public function testProvisioningUriMatchesTheKnownMatrix(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
        $this->assertSame(
            'otpauth://totp/RunnerDeck?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=RunnerDeck',
            $uri
        );
        $this->assertSame(
            'fb7631376b4ce44dcee2c30532981e78f445530ff9c0b2a48234689a7383c3e5',
            self::matrixHash($uri)
        );
        $this->assertCount(37, QrCode::modules($uri));
    }

    public function testVersionSevenCarriesVersionInfo(): void
    {
        $payload = str_repeat('a', 107);
        $this->assertSame(
            '82a2ee68153b708e252079b819df0c3a29cfc956deccef987a362e54c1a96b81',
            self::matrixHash($payload)
        );
        $this->assertCount(45, QrCode::modules($payload));
    }

    public function testSvgKeepsAWhiteQuietZone(): void
    {
        $svg = QrCode::svg('hello');
        $this->assertStringContainsString('fill="#fff"', $svg);
        $this->assertStringContainsString('<rect x="', $svg);
        $this->assertStringContainsString('role="img"', $svg);
    }

    public function testTerminalDrawsHalfBlocks(): void
    {
        $picture = QrCode::terminal('hello');
        $this->assertStringContainsString('█', $picture);
        $this->assertStringContainsString("\n", $picture);
    }

    public function testAPayloadPastVersionTenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::modules(str_repeat('a', 300));
    }

    public function testAShortTerminatorIsPaddedOutToAByte(): void
    {
        $method = new \ReflectionMethod(QrCode::class, 'dataBits');
        $bits = $method->invoke(null, str_repeat('a', 15), 1);
        $this->assertIsArray($bits);
        $this->assertSame(0, count($bits) % 8);
    }

    public function testMultiplyByZeroStaysZero(): void
    {
        $method = new \ReflectionMethod(QrCode::class, 'mul');
        $this->assertSame(0, $method->invoke(null, 0, 5));
        $this->assertSame(0, $method->invoke(null, 5, 0));
    }

    private static function matrixHash(string $payload): string
    {
        $bits = '';
        foreach (QrCode::modules($payload) as $row) {
            foreach ($row as $dark) {
                $bits .= $dark ? '1' : '0';
            }
        }
        return hash('sha256', $bits);
    }
}
