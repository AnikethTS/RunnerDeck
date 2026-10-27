<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'RUNNERDECK_ORG', 'RUNNERDECK_REPO', 'RUNNERDECK_LABEL', 'RUNNERDECK_EXTRA_LABELS',
        'RUNNERDECK_POOL_DIR', 'RUNNERDECK_SCOPE', 'RUNNERDECK_CHECK_UPDATES',
        'RUNNERDECK_AUTH_TOTP_SECRET', 'RUNNERDECK_AUTO_RESTART', 'RUNNERDECK_SCALE_TO_QUEUE',
        'RUNNERDECK_POOL_CAP', 'RUNNERDECK_IDLE_STOP_MINUTES', 'RUNNERDECK_AUTO_CLEAR_WORK',
        'RUNNERDECK_QUEUE_WAIT_MINUTES',
        'RUNNERDECK_LONG_JOB_MINUTES',
        'RUNNERDECK_MIN_FREE_DISK_GIB', 'RUNNERDECK_MIN_FREE_RAM_MIB',
        'RUNNERDECK_CRASH_WEBHOOK_URL',
        'RUNNERDECK_CRASH_WEBHOOK_THRESHOLD', 'RUNNERDECK_DRAIN_TIMEOUT',
        'RUNNERDECK_DISK_WEBHOOK_THRESHOLD', 'RUNNERDECK_SESSION_IDLE_MINUTES',
        'RUNNERDECK_TRUST_PROXY',
        'RUNNERDECK_SYSLOG', 'RUNNERDECK_SYSLOG_SOCKET',
        'RUNNERDECK_SLOT_CPU_PERCENT', 'RUNNERDECK_SLOT_MEMORY_MAX_MIB',
        'RUNNERDECK_GITHUB_APP_ID', 'RUNNERDECK_GITHUB_APP_INSTALLATION_ID',
        'RUNNERDECK_GITHUB_APP_KEY_FILE',
        'RUNNERDECK_BUNDLE_SNAPSHOT', 'RUNNERDECK_BUNDLE_SNAPSHOT_SECRETS',
        'RUNNERDECK_LOG_RETENTION_DAYS', 'RUNNERDECK_AGENT_SHA',
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
        \RunnerDeck\Core\Config::org();
    }

    #[RunInSeparateProcess]
    public function testOrgReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_ORG=my-org');
        $this->assertSame('my-org', \RunnerDeck\Core\Config::org());
    }

    #[RunInSeparateProcess]
    public function testLabelDefaultsWhenUnset(): void
    {
        $this->assertSame('self-hosted-runnerdeck', \RunnerDeck\Core\Config::label());
    }

    #[RunInSeparateProcess]
    public function testLabelReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_LABEL=custom-label');
        $this->assertSame('custom-label', \RunnerDeck\Core\Config::label());
    }

    #[RunInSeparateProcess]
    public function testProvisionLabelsMergesExtrasAndIgnoresInvalidEnv(): void
    {
        $this->assertSame('self-hosted-runnerdeck', \RunnerDeck\Core\Config::provisionLabels());
        $this->assertSame(['gpu', 'linux'], \RunnerDeck\Core\Config::parseLabels(' gpu, linux,gpu '));
        $this->assertNull(\RunnerDeck\Core\Config::parseLabels('not a label'));
        putenv('RUNNERDECK_EXTRA_LABELS=gpu, linux');
        $this->assertSame(['gpu', 'linux'], \RunnerDeck\Core\Config::extraLabels());
        $this->assertSame('self-hosted-runnerdeck,gpu,linux', \RunnerDeck\Core\Config::provisionLabels());
        putenv('RUNNERDECK_EXTRA_LABELS=bad label');
        $this->assertSame([], \RunnerDeck\Core\Config::extraLabels());
        $this->assertSame('self-hosted-runnerdeck', \RunnerDeck\Core\Config::provisionLabels());
    }

    #[RunInSeparateProcess]
    public function testPoolDirStripsTrailingSlash(): void
    {
        putenv('RUNNERDECK_POOL_DIR=/tmp/runners/');
        $this->assertSame('/tmp/runners', \RunnerDeck\Core\Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testPoolDirDefaultsNextToRepoCheckout(): void
    {
        $expected = dirname(__DIR__, 2) . '/runners';
        $this->assertSame($expected, \RunnerDeck\Core\Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testScopeDefaultsToOrg(): void
    {
        $this->assertSame('org', \RunnerDeck\Core\Config::scope());
    }

    #[RunInSeparateProcess]
    public function testScopeAcceptsRepo(): void
    {
        putenv('RUNNERDECK_SCOPE=repo');
        $this->assertSame('repo', \RunnerDeck\Core\Config::scope());
    }

    #[RunInSeparateProcess]
    public function testScopeRejectsInvalidValue(): void
    {
        putenv('RUNNERDECK_SCOPE=user');
        $this->expectException(RuntimeException::class);
        \RunnerDeck\Core\Config::scope();
    }

    #[RunInSeparateProcess]
    public function testRepoThrowsWhenUnset(): void
    {
        $this->expectException(RuntimeException::class);
        \RunnerDeck\Core\Config::repo();
    }

    #[RunInSeparateProcess]
    public function testRepoReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_REPO=AnikethTS/RunnerDeck');
        $this->assertSame('AnikethTS/RunnerDeck', \RunnerDeck\Core\Config::repo());
    }

    #[RunInSeparateProcess]
    public function testCheckUpdatesEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Config::checkUpdatesEnabled());
    }

    #[RunInSeparateProcess]
    public function testCheckUpdatesEnabledTrueWhenSetTo1(): void
    {
        putenv('RUNNERDECK_CHECK_UPDATES=1');
        $this->assertTrue(\RunnerDeck\Core\Config::checkUpdatesEnabled());
    }

    #[RunInSeparateProcess]
    public function testAutoRestartEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Config::autoRestartEnabled());
    }

    #[RunInSeparateProcess]
    public function testAutoRestartEnabledTrueWhenSetTo1(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        $this->assertTrue(\RunnerDeck\Core\Config::autoRestartEnabled());
    }

    #[RunInSeparateProcess]
    public function testScaleToQueueEnabledTrueWhenSetTo1(): void
    {
        putenv('RUNNERDECK_SCALE_TO_QUEUE=1');
        $this->assertTrue(\RunnerDeck\Core\Config::scaleToQueueEnabled());
    }

    #[RunInSeparateProcess]
    public function testPoolCapAndIdleStopParseEnv(): void
    {
        $this->assertNull(\RunnerDeck\Core\Config::poolCap());
        $this->assertNull(\RunnerDeck\Core\Config::idleStopMinutes());
        $this->assertFalse(\RunnerDeck\Core\Config::autoClearWork());
        $this->assertNull(\RunnerDeck\Core\Config::queueWaitMinutes());
        putenv('RUNNERDECK_POOL_CAP=8');
        putenv('RUNNERDECK_IDLE_STOP_MINUTES=15');
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES=20');
        $this->assertSame(8, \RunnerDeck\Core\Config::poolCap());
        $this->assertSame(15, \RunnerDeck\Core\Config::idleStopMinutes());
        $this->assertSame(20, \RunnerDeck\Core\Config::queueWaitMinutes());
        putenv('RUNNERDECK_LONG_JOB_MINUTES=45');
        putenv('RUNNERDECK_AUTO_CLEAR_WORK=1');
        $this->assertSame(45, \RunnerDeck\Core\Config::longJobMinutes());
        $this->assertTrue(\RunnerDeck\Core\Config::autoClearWork());
    }

    #[RunInSeparateProcess]
    public function testMinFreeDiskAndRamDefaultsAndZeroOff(): void
    {
        $this->assertSame(1, \RunnerDeck\Core\Config::minFreeDiskGib());
        $this->assertSame(256, \RunnerDeck\Core\Config::minFreeRamMib());
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB=0');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB=0');
        $this->assertSame(0, \RunnerDeck\Core\Config::minFreeDiskGib());
        $this->assertSame(0, \RunnerDeck\Core\Config::minFreeRamMib());
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB=4');
        $this->assertSame(4, \RunnerDeck\Core\Config::minFreeDiskGib());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookUrlDefaultsToNull(): void
    {
        $this->assertNull(\RunnerDeck\Core\Config::crashWebhookUrl());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookUrlReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://hooks.slack.com/services/x');
        $this->assertSame('https://hooks.slack.com/services/x', \RunnerDeck\Core\Config::crashWebhookUrl());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookThresholdDefaultsToNull(): void
    {
        $this->assertNull(\RunnerDeck\Core\Config::crashWebhookThreshold());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookThresholdParsesPositiveInt(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_THRESHOLD=5');
        $this->assertSame(5, \RunnerDeck\Core\Config::crashWebhookThreshold());
    }

    #[RunInSeparateProcess]
    public function testCrashWebhookThresholdRejectsZeroAndGarbage(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_THRESHOLD=0');
        $this->assertNull(\RunnerDeck\Core\Config::crashWebhookThreshold());
        putenv('RUNNERDECK_CRASH_WEBHOOK_THRESHOLD=nope');
        $this->assertNull(\RunnerDeck\Core\Config::crashWebhookThreshold());
    }

    #[RunInSeparateProcess]
    public function testDrainTimeoutSecondsDefaultsTo600(): void
    {
        $this->assertSame(600, \RunnerDeck\Core\Config::drainTimeoutSeconds());
    }

    #[RunInSeparateProcess]
    public function testDrainTimeoutSecondsCapsAt3600(): void
    {
        putenv('RUNNERDECK_DRAIN_TIMEOUT=99999');
        $this->assertSame(3600, \RunnerDeck\Core\Config::drainTimeoutSeconds());
    }

    #[RunInSeparateProcess]
    public function testDiskWebhookThresholdDefaultsToNull(): void
    {
        $this->assertNull(\RunnerDeck\Core\Config::diskWebhookThresholdGb());
    }

    #[RunInSeparateProcess]
    public function testDiskWebhookThresholdParsesPositiveInt(): void
    {
        putenv('RUNNERDECK_DISK_WEBHOOK_THRESHOLD=10');
        $this->assertSame(10, \RunnerDeck\Core\Config::diskWebhookThresholdGb());
    }

    #[RunInSeparateProcess]
    public function testSessionIdleMinutesDefaultsToNull(): void
    {
        $this->assertNull(\RunnerDeck\Core\Config::sessionIdleMinutes());
    }

    #[RunInSeparateProcess]
    public function testSessionIdleMinutesCapsAt1440(): void
    {
        putenv('RUNNERDECK_SESSION_IDLE_MINUTES=9999');
        $this->assertSame(1440, \RunnerDeck\Core\Config::sessionIdleMinutes());
    }

    #[RunInSeparateProcess]
    public function testAuthEnabledDefaultsToFalse(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Config::authEnabled());
        $this->assertNull(\RunnerDeck\Core\Config::authTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testAuthEnabledTrueWhenSecretSet(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $this->assertTrue(\RunnerDeck\Core\Config::authEnabled());
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', \RunnerDeck\Core\Config::authTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testVersionReadsFromVersionFile(): void
    {
        $expected = trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'));
        $this->assertSame($expected, \RunnerDeck\Core\Config::version());
    }

    #[RunInSeparateProcess]
    public function testTrustProxyDefaultsOff(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Config::trustProxy());
    }

    #[RunInSeparateProcess]
    public function testTrustProxyEnabledWhenSet(): void
    {
        putenv('RUNNERDECK_TRUST_PROXY=1');
        $this->assertTrue(\RunnerDeck\Core\Config::trustProxy());
    }

    #[RunInSeparateProcess]
    public function testSlotCapsAndSyslogParse(): void
    {
        $this->assertNull(\RunnerDeck\Core\Config::slotCpuPercent());
        $this->assertNull(\RunnerDeck\Core\Config::slotMemoryMaxMib());
        $this->assertFalse(\RunnerDeck\Core\Config::syslogEnabled());
        putenv('RUNNERDECK_SLOT_CPU_PERCENT=40');
        putenv('RUNNERDECK_SLOT_MEMORY_MAX_MIB=512');
        putenv('RUNNERDECK_SYSLOG=1');
        $this->assertSame(40, \RunnerDeck\Core\Config::slotCpuPercent());
        $this->assertSame(512, \RunnerDeck\Core\Config::slotMemoryMaxMib());
        $this->assertTrue(\RunnerDeck\Core\Config::syslogEnabled());
        putenv('RUNNERDECK_SLOT_MEMORY_MAX_MIB=8');
        $this->assertNull(\RunnerDeck\Core\Config::slotMemoryMaxMib());
    }

    #[RunInSeparateProcess]
    public function testBundleSnapshotFlags(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Config::bundleSnapshotEnabled());
        $this->assertFalse(\RunnerDeck\Core\Config::bundleSnapshotSecrets());
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT_SECRETS=1');
        $this->assertFalse(\RunnerDeck\Core\Config::bundleSnapshotSecrets());
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        $this->assertTrue(\RunnerDeck\Core\Config::bundleSnapshotEnabled());
        $this->assertTrue(\RunnerDeck\Core\Config::bundleSnapshotSecrets());
    }

    #[RunInSeparateProcess]
    public function testLogRetentionAndAgentSha(): void
    {
        $this->assertNull(\RunnerDeck\Core\Config::logRetentionDays());
        $this->assertNull(\RunnerDeck\Core\Config::agentSha());
        putenv('RUNNERDECK_LOG_RETENTION_DAYS=14');
        $this->assertSame(14, \RunnerDeck\Core\Config::logRetentionDays());
        putenv('RUNNERDECK_LOG_RETENTION_DAYS=999');
        $this->assertSame(365, \RunnerDeck\Core\Config::logRetentionDays());
        putenv('RUNNERDECK_LOG_RETENTION_DAYS=0');
        $this->assertNull(\RunnerDeck\Core\Config::logRetentionDays());
        $sha = str_repeat('ab', 32);
        putenv('RUNNERDECK_AGENT_SHA=' . strtoupper($sha));
        $this->assertSame($sha, \RunnerDeck\Core\Config::agentSha());
        putenv('RUNNERDECK_AGENT_SHA=not-a-sha');
        $this->assertNull(\RunnerDeck\Core\Config::agentSha());
    }
}
