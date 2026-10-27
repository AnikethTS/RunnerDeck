<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GithubQuotaTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-quota-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        \RunnerDeck\GitHub\GithubQuota::fakeNow(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\GitHub\GithubQuota::fakeNow(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testMissingRemainingFailsOpen(): void
    {
        \RunnerDeck\GitHub\GithubQuota::observe(null, 5000, 1_700_000_000);
        $this->assertNull(\RunnerDeck\GitHub\GithubQuota::warning());
        $this->assertNull(\RunnerDeck\GitHub\GithubQuota::snapshot()['remaining']);
    }

    #[RunInSeparateProcess]
    public function testHealthyQuotaIsSilent(): void
    {
        \RunnerDeck\GitHub\GithubQuota::observe(4500, 5000, null);
        $this->assertNull(\RunnerDeck\GitHub\GithubQuota::warning());
        $this->assertSame(4500, \RunnerDeck\GitHub\GithubQuota::snapshot()['remaining']);
        $this->assertSame(5000, \RunnerDeck\GitHub\GithubQuota::snapshot()['limit']);
    }

    #[RunInSeparateProcess]
    public function testLowRemainingWarns(): void
    {
        $now = 1_700_000_000;
        \RunnerDeck\GitHub\GithubQuota::fakeNow(fn () => $now);
        \RunnerDeck\GitHub\GithubQuota::observe(50, 5000, $now + 600);
        $text = \RunnerDeck\GitHub\GithubQuota::warning();
        $this->assertNotNull($text);
        $this->assertStringContainsString('50/5000', $text);
        $this->assertStringContainsString('resets in about 10 min', $text);
    }

    #[RunInSeparateProcess]
    public function testTenPercentOfLimitWarns(): void
    {
        \RunnerDeck\GitHub\GithubQuota::observe(150, 2000, null);
        $text = \RunnerDeck\GitHub\GithubQuota::warning();
        $this->assertNotNull($text);
        $this->assertStringContainsString('150/2000', $text);
    }
}
