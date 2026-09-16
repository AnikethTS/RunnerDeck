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
        'RUNNERDECK_AUTH_TOTP_SECRET', 'RUNNERDECK_AUTO_RESTART',
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
        \Config::org();
    }

    #[RunInSeparateProcess]
    public function testOrgReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_ORG=my-org');
        $this->assertSame('my-org', \Config::org());
    }

    #[RunInSeparateProcess]
    public function testLabelDefaultsWhenUnset(): void
    {
        $this->assertSame('self-hosted-runnerdeck', \Config::label());
    }

    #[RunInSeparateProcess]
    public function testLabelReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_LABEL=custom-label');
        $this->assertSame('custom-label', \Config::label());
    }

    #[RunInSeparateProcess]
    public function testPoolDirStripsTrailingSlash(): void
    {
        putenv('RUNNERDECK_POOL_DIR=/tmp/runners/');
        $this->assertSame('/tmp/runners', \Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testPoolDirDefaultsNextToRepoCheckout(): void
    {
        $expected = dirname(__DIR__, 2) . '/runners';
        $this->assertSame($expected, \Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testScopeDefaultsToOrg(): void
    {
        $this->assertSame('org', \Config::scope());
    }

    #[RunInSeparateProcess]
    public function testScopeAcceptsRepo(): void
    {
        putenv('RUNNERDECK_SCOPE=repo');
        $this->assertSame('repo', \Config::scope());
    }

    #[RunInSeparateProcess]
    public function testScopeRejectsInvalidValue(): void
    {
        putenv('RUNNERDECK_SCOPE=user');
        $this->expectException(RuntimeException::class);
        \Config::scope();
    }

    #[RunInSeparateProcess]
    public function testRepoThrowsWhenUnset(): void
    {
        $this->expectException(RuntimeException::class);
        \Config::repo();
    }

    #[RunInSeparateProcess]
    public function testRepoReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_REPO=AnikethTS/RunnerDeck');
        $this->assertSame('AnikethTS/RunnerDeck', \Config::repo());
    }

    #[RunInSeparateProcess]
    public function testCheckUpdatesEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\Config::checkUpdatesEnabled());
    }

    #[RunInSeparateProcess]
    public function testCheckUpdatesEnabledTrueWhenSetTo1(): void
    {
        putenv('RUNNERDECK_CHECK_UPDATES=1');
        $this->assertTrue(\Config::checkUpdatesEnabled());
    }

    #[RunInSeparateProcess]
    public function testAutoRestartEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\Config::autoRestartEnabled());
    }

    #[RunInSeparateProcess]
    public function testAutoRestartEnabledTrueWhenSetTo1(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        $this->assertTrue(\Config::autoRestartEnabled());
    }

    #[RunInSeparateProcess]
    public function testAuthEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\Config::authEnabled());
        $this->assertNull(\Config::authTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testAuthEnabledTrueWhenSecretSet(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $this->assertTrue(\Config::authEnabled());
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', \Config::authTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testVersionReadsFromVersionFile(): void
    {
        $expected = trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'));
        $this->assertSame($expected, \Config::version());
    }
}
