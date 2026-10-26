<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GithubClockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-clock-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        \RunnerDeck\GitHub\GithubClock::fakeNow(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\GitHub\GithubClock::fakeNow(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testMissingDateFailsOpen(): void
    {
        \RunnerDeck\GitHub\GithubClock::observeHttpDate(null);
        \RunnerDeck\GitHub\GithubClock::observeHttpDate('not a date');
        $this->assertNull(\RunnerDeck\GitHub\GithubClock::warning());
    }

    #[RunInSeparateProcess]
    public function testSmallSkewIsSilent(): void
    {
        $host = 1_700_000_000;
        \RunnerDeck\GitHub\GithubClock::fakeNow(fn () => $host);
        \RunnerDeck\GitHub\GithubClock::observeHttpDate(gmdate('D, d M Y H:i:s', $host + 30) . ' GMT');
        $this->assertNull(\RunnerDeck\GitHub\GithubClock::warning());
    }

    #[RunInSeparateProcess]
    public function testLargeSkewWarns(): void
    {
        $host = 1_700_000_000;
        \RunnerDeck\GitHub\GithubClock::fakeNow(fn () => $host);
        \RunnerDeck\GitHub\GithubClock::observeHttpDate(gmdate('D, d M Y H:i:s', $host + 720) . ' GMT');
        $text = \RunnerDeck\GitHub\GithubClock::warning();
        $this->assertNotNull($text);
        $this->assertStringContainsString('behind', $text);
        $this->assertStringContainsString('NTP', $text);
    }
}
