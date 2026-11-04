<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GithubBackoffTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-backoff-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        \RunnerDeck\GitHub\GithubBackoff::fakeNow(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\GitHub\GithubBackoff::fakeNow(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testLooksLimitedOn429NotOnGeneric403(): void
    {
        $this->assertTrue(\RunnerDeck\GitHub\GithubBackoff::looksLimited('HTTP 429: API rate limit exceeded'));
        $this->assertTrue(
            \RunnerDeck\GitHub\GithubBackoff::looksLimited('You have exceeded a secondary rate limit')
        );
        $this->assertFalse(\RunnerDeck\GitHub\GithubBackoff::looksLimited('HTTP 403: Resource not accessible'));
        $this->assertFalse(\RunnerDeck\GitHub\GithubBackoff::looksLimited('network timeout'));
    }

    #[RunInSeparateProcess]
    public function testNoteFailureCoolsThenClear(): void
    {
        $t = 1_700_000_000;
        \RunnerDeck\GitHub\GithubBackoff::fakeNow(fn () => $t);
        \RunnerDeck\GitHub\GithubBackoff::noteFailure('HTTP 429: API rate limit exceeded');
        $this->assertTrue(\RunnerDeck\GitHub\GithubBackoff::isCooling());
        $this->assertSame(30, \RunnerDeck\GitHub\GithubBackoff::remaining());
        $this->assertNotNull(\RunnerDeck\GitHub\GithubBackoff::warning());

        \RunnerDeck\GitHub\GithubBackoff::fakeNow(fn () => $t + 31);
        $this->assertFalse(\RunnerDeck\GitHub\GithubBackoff::isCooling());
        $this->assertNull(\RunnerDeck\GitHub\GithubBackoff::warning());
    }

    #[RunInSeparateProcess]
    public function testRetryAfterHeaderWins(): void
    {
        $t = 1_700_000_000;
        \RunnerDeck\GitHub\GithubBackoff::fakeNow(fn () => $t);
        \RunnerDeck\GitHub\GithubBackoff::noteFailure("HTTP 429\nRetry-After: 90\nrate limit exceeded");
        $this->assertSame(90, \RunnerDeck\GitHub\GithubBackoff::remaining());
    }

    #[RunInSeparateProcess]
    public function testThrowIfCooling(): void
    {
        \RunnerDeck\GitHub\GithubBackoff::fakeNow(fn () => 1_700_000_000);
        \RunnerDeck\GitHub\GithubBackoff::noteFailure('HTTP 429: rate limit exceeded');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cooling down');
        \RunnerDeck\GitHub\GithubBackoff::throwIfCooling();
    }

    #[RunInSeparateProcess]
    public function testClearRemovesCooldown(): void
    {
        \RunnerDeck\GitHub\GithubBackoff::fakeNow(fn () => 1_700_000_000);
        \RunnerDeck\GitHub\GithubBackoff::noteFailure('HTTP 429: rate limit exceeded');
        \RunnerDeck\GitHub\GithubBackoff::clear();
        $this->assertFalse(\RunnerDeck\GitHub\GithubBackoff::isCooling());
    }
}
