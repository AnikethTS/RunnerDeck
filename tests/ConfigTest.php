<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'RUNNERDECK_ORG', 'RUNNERDECK_REPO', 'RUNNERDECK_LABEL',
        'RUNNERDECK_POOL_DIR', 'RUNNERDECK_SCOPE', 'RUNNERDECK_CHECK_UPDATES',
        'RUNNERDECK_AUTH_TOTP_SECRET', 'RUNNERDECK_AUTO_RESTART', 'RUNNERDECK_CRASH_WEBHOOK_URL',
        'RUNNERDECK_CRASH_WEBHOOK_THRESHOLD',
    ];

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
    }

    #[RunInSeparateProcess]
    public function testOrgThrowsWhenUnset(): void
    {
        $this->expectException(RuntimeException::class);
        \RunnerDeck\Config::org();
    }

    #[RunInSeparateProcess]
    public function testOrgReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_ORG=my-org');
        $this->assertSame('my-org', \RunnerDeck\Config::org());
    }

    #[RunInSeparateProcess]
    public function testLabelDefaultsWhenUnset(): void
    {
        $this->assertSame('self-hosted-runnerdeck', \RunnerDeck\Config::label());
    }

    #[RunInSeparateProcess]
    public function testLabelReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_LABEL=custom-label');
        $this->assertSame('custom-label', \RunnerDeck\Config::label());
    }

    #[RunInSeparateProcess]
    public function testPoolDirStripsTrailingSlash(): void
    {
        putenv('RUNNERDECK_POOL_DIR=/tmp/runners/');
        $this->assertSame('/tmp/runners', \RunnerDeck\Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testPoolDirDefaultsNextToRepoCheckout(): void
    {
        $expected = dirname(__DIR__, 2) . '/runners';
        $this->assertSame($expected, \RunnerDeck\Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testScopeDefaultsToOrg(): void
    {
        $this->assertSame('org', \RunnerDeck\Config::scope());
    }

    #[RunInSeparateProcess]
    public function testScopeAcceptsRepo(): void
    {
        putenv('RUNNERDECK_SCOPE=repo');
        $this->assertSame('repo', \RunnerDeck\Config::scope());
    }

    #[RunInSeparateProcess]
    public function testScopeRejectsInvalidValue(): void
    {
        putenv('RUNNERDECK_SCOPE=user');
        $this->expectException(RuntimeException::class);
        \RunnerDeck\Config::scope();
    }

    #[RunInSeparateProcess]
    public function testRepoThrowsWhenUnset(): void
    {
        $this->expectException(RuntimeException::class);
        \RunnerDeck\Config::repo();
    }

    #[RunInSeparateProcess]
    public function testRepoReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_REPO=AnikethTS/RunnerDeck');
        $this->assertSame('AnikethTS/RunnerDeck', \RunnerDeck\Config::repo());
    }

    #[RunInSeparateProcess]
    public function testCheckUpdatesEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\RunnerDeck\Config::checkUpdatesEnabled());
    }

    #[RunInSeparateProcess]
    public function testCheckUpdatesEnabledTrueWhenSetTo1(): void
    {
        putenv('RUNNERDECK_CHECK_UPDATES=1');
        $this->assertTrue(\RunnerDeck\Config::checkUpdatesEnabled());
    }

    #[RunInSeparateProcess]
    public function testAutoRestartEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\RunnerDeck\Config::autoRestartEnabled());
    }

    #[RunInSeparateProcess]
    public function testAutoRestartEnabledTrueWhenSetTo1(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        $this->assertTrue(\RunnerDeck\Config::autoRestartEnabled());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookUrlDefaultsToNull(): void
    {
        $this->assertNull(\RunnerDeck\Config::crashWebhookUrl());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookUrlReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://hooks.slack.com/services/x');
        $this->assertSame('https://hooks.slack.com/services/x', \RunnerDeck\Config::crashWebhookUrl());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookThresholdDefaultsToNull(): void
    {
        $this->assertNull(\RunnerDeck\Config::crashWebhookThreshold());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookThresholdParsesPositiveInt(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_THRESHOLD=5');
        $this->assertSame(5, \RunnerDeck\Config::crashWebhookThreshold());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookThresholdRejectsZeroAndGarbage(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_THRESHOLD=0');
        $this->assertNull(\RunnerDeck\Config::crashWebhookThreshold());
        putenv('RUNNERDECK_CRASH_WEBHOOK_THRESHOLD=nope');
        $this->assertNull(\RunnerDeck\Config::crashWebhookThreshold());
    }

    #[RunInSeparateProcess]
    public function testAuthEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\RunnerDeck\Config::authEnabled());
        $this->assertNull(\RunnerDeck\Config::authTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testAuthEnabledTrueWhenSecretSet(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $this->assertTrue(\RunnerDeck\Config::authEnabled());
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', \RunnerDeck\Config::authTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testVersionReadsFromVersionFile(): void
    {
        $expected = trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'));
        $this->assertSame($expected, \RunnerDeck\Config::version());
    }
}
