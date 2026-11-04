<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Core\Banner;
use RunnerDeck\Core\Config;

final class BannerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('RUNNERDECK_NO_BANNER');
    }

    public function testPlainBannerCarriesVersionCreditsAndLinks(): void
    {
        $text = Banner::render(false);

        $this->assertStringContainsString('Welcome to RunnerDeck v' . Config::version(), $text);
        $this->assertStringContainsString('Built by Aniketh TS and contributors', $text);
        $this->assertStringContainsString('https://github.com/AnikethTS/RunnerDeck', $text);
        $this->assertStringNotContainsString("\033", $text);
    }

    public function testBannerDrawsTheLogoBesideTheText(): void
    {
        $lines = explode("\n", Banner::render(false));
        $welcome = array_values(array_filter(
            $lines,
            static fn (string $l): bool => str_contains($l, 'Welcome to RunnerDeck')
        ));

        $this->assertCount(1, $welcome);
        $this->assertStringContainsString('@@@', $welcome[0]);
        $this->assertStringContainsString('#', $welcome[0]);
        foreach ($lines as $line) {
            $this->assertSame(rtrim($line), $line);
        }
    }

    public function testColorBannerUsesAnsiCodes(): void
    {
        $this->assertStringContainsString("\033[", Banner::render(true));
    }

    public function testBannerIsAsciiSoWindowsConsolesShowItIntact(): void
    {
        $this->assertSame(1, preg_match('/^[\x09\x0A\x20-\x7E]*$/', Banner::render(false)));
    }

    public function testShowPrintsTheBanner(): void
    {
        ob_start();
        Banner::show();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('Welcome to RunnerDeck', $out);
    }

    public function testShowIsSilentWhenDisabled(): void
    {
        putenv('RUNNERDECK_NO_BANNER=1');

        ob_start();
        Banner::show();
        $out = (string) ob_get_clean();

        $this->assertSame('', $out);
    }
}
