<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class GithubAuthTest extends TestCase
{
    public function testLooksStaleOn401AndNotOnGenericErrors(): void
    {
        $this->assertTrue(\RunnerDeck\GitHub\GithubAuth::looksStale('HTTP 401: Bad credentials'));
        $this->assertTrue(\RunnerDeck\GitHub\GithubAuth::looksStale('Resource not accessible by integration'));
        $this->assertFalse(\RunnerDeck\GitHub\GithubAuth::looksStale('network timeout'));
        $this->assertFalse(\RunnerDeck\GitHub\GithubAuth::looksStale('no gh'));
    }

    public function testStaleWarningSilentWhenGithubOk(): void
    {
        $this->assertNull(\RunnerDeck\GitHub\GithubAuth::staleWarning(true, true, 'HTTP 401 leftover'));
    }

    public function testStaleWarningWhenLoggedInButForbidden(): void
    {
        $text = \RunnerDeck\GitHub\GithubAuth::staleWarning(
            true,
            false,
            'Logged in, but cannot read org runners: HTTP 403'
        );
        $this->assertNotNull($text);
        $this->assertStringContainsString('cannot read runners', $text);
    }
}
