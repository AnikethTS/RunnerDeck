<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsTest extends TestCase
{
    private string $settingsFile;

    protected function setUp(): void
    {
        $this->settingsFile = sys_get_temp_dir() . '/runnerdeck-settings-test-' . uniqid() . '/settings.json';
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->settingsFile);
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_REPO');
        putenv('RUNNERDECK_LABEL');
        putenv('RUNNERDECK_EXTRA_LABELS');
        putenv('RUNNERDECK_CHECK_UPDATES');
        putenv('RUNNERDECK_POOL_CAP');
        putenv('RUNNERDECK_IDLE_STOP_MINUTES');
        putenv('RUNNERDECK_AUTO_CLEAR_WORK');
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES');
        putenv('RUNNERDECK_LONG_JOB_MINUTES');
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB');
        putenv('RUNNERDECK_GITHUB_APP_ID');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID');
        @unlink($this->settingsFile);
        @rmdir(dirname($this->settingsFile));
    }

    #[RunInSeparateProcess]
    public function testLoadReturnsEmptyArrayWhenFileMissing(): void
    {
        $this->assertSame([], \RunnerDeck\Core\Settings::load());
    }

    #[RunInSeparateProcess]
    public function testIsConfiguredFalseWhenUnset(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Settings::isConfigured());
    }

    #[RunInSeparateProcess]
    public function testSaveThenLoadRoundTrips(): void
    {
        \RunnerDeck\Core\Settings::save([
            'RUNNERDECK_SCOPE' => 'repo',
            'RUNNERDECK_REPO' => 'AnikethTS/RunnerDeck',
            'RUNNERDECK_ORG' => '',
            'RUNNERDECK_LABEL' => 'my-label',
        ]);

        $loaded = \RunnerDeck\Core\Settings::load();

        $this->assertSame('repo', $loaded['RUNNERDECK_SCOPE']);
        $this->assertSame('AnikethTS/RunnerDeck', $loaded['RUNNERDECK_REPO']);
        $this->assertSame('my-label', $loaded['RUNNERDECK_LABEL']);
        $this->assertArrayNotHasKey('RUNNERDECK_ORG', $loaded, 'empty values should be dropped, not saved as blanks');
    }

    #[RunInSeparateProcess]
    public function testIsConfiguredTrueAfterSavingOrgScope(): void
    {
        \RunnerDeck\Core\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'my-org']);
        $this->assertTrue(\RunnerDeck\Core\Settings::isConfigured());
    }

    #[RunInSeparateProcess]
    public function testIsConfiguredFalseWhenRepoScopeMissingRepo(): void
    {
        \RunnerDeck\Core\Settings::save(['RUNNERDECK_SCOPE' => 'repo']);
        $this->assertFalse(\RunnerDeck\Core\Settings::isConfigured());
    }

    #[RunInSeparateProcess]
    public function testApplyToEnvDoesNotOverrideRealEnvVar(): void
    {
        \RunnerDeck\Core\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'from-settings-file']);
        putenv('RUNNERDECK_ORG=from-real-env');

        \RunnerDeck\Core\Settings::applyToEnv();

        $this->assertSame('from-real-env', getenv('RUNNERDECK_ORG'));
    }

    #[RunInSeparateProcess]
    public function testApplyToEnvSetsUnsetKeysFromSavedSettings(): void
    {
        \RunnerDeck\Core\Settings::save(['RUNNERDECK_SCOPE' => 'repo', 'RUNNERDECK_REPO' => 'owner/repo']);

        \RunnerDeck\Core\Settings::applyToEnv();

        $this->assertSame('repo', getenv('RUNNERDECK_SCOPE'));
        $this->assertSame('owner/repo', getenv('RUNNERDECK_REPO'));
    }

    #[RunInSeparateProcess]
    public function testSaveThenLoadRoundTripsCheckUpdates(): void
    {
        \RunnerDeck\Core\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'my-org',
            'RUNNERDECK_CHECK_UPDATES' => '1',
        ]);

        $loaded = \RunnerDeck\Core\Settings::load();

        $this->assertSame('1', $loaded['RUNNERDECK_CHECK_UPDATES']);
    }

    #[RunInSeparateProcess]
    public function testSaveSettingsActionLogsWithoutWebhookUrl(): void
    {
        $result = \RunnerDeck\Http\Action\SaveSettingsAction::save([
            'scope' => 'org',
            'org' => 'acme',
            'crash_webhook_url' => 'https://example.com/secret-hook',
            'auto_restart' => '1',
        ]);
        $this->assertTrue($result['ok']);

        $raw = (string) file_get_contents(dirname($this->settingsFile) . '/runnerdeck.log');
        $this->assertStringContainsString('settings.save', $raw);
        $this->assertStringContainsString('webhook=set', $raw);
        $this->assertStringNotContainsString('secret-hook', $raw);
        $this->assertStringNotContainsString('https://example.com', $raw);
    }

    #[RunInSeparateProcess]
    public function testSaveSettingsActionRejectsInvalidExtraLabels(): void
    {
        $result = \RunnerDeck\Http\Action\SaveSettingsAction::save([
            'scope' => 'org',
            'org' => 'acme',
            'extra_labels' => 'not a label',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('extra labels', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testSaveSettingsActionStoresNormalizedExtraLabels(): void
    {
        $result = \RunnerDeck\Http\Action\SaveSettingsAction::save([
            'scope' => 'org',
            'org' => 'acme',
            'extra_labels' => 'gpu, linux,gpu',
        ]);
        $this->assertTrue($result['ok']);
        $loaded = \RunnerDeck\Core\Settings::load();
        $this->assertSame('gpu,linux', $loaded['RUNNERDECK_EXTRA_LABELS']);
    }

    #[RunInSeparateProcess]
    public function testSaveSettingsActionStoresPoolCapAndIdleStop(): void
    {
        $result = \RunnerDeck\Http\Action\SaveSettingsAction::save([
            'scope' => 'org',
            'org' => 'acme',
            'pool_cap' => '4',
            'idle_stop_minutes' => '10',
            'queue_wait_minutes' => '15',
            'long_job_minutes' => '90',
            'auto_clear_work' => '1',
            'syslog' => '1',
            'slot_cpu_percent' => '50',
            'slot_memory_max_mib' => '1024',
            'github_app_id' => '99',
            'github_app_installation_id' => '1001',
            'bundle_snapshot' => '1',
            'bundle_snapshot_secrets' => '1',
            'log_retention_days' => '14',
            'agent_sha' => str_repeat('AB', 32),
        ]);
        $this->assertTrue($result['ok']);
        $loaded = \RunnerDeck\Core\Settings::load();
        $this->assertSame('4', $loaded['RUNNERDECK_POOL_CAP']);
        $this->assertSame('10', $loaded['RUNNERDECK_IDLE_STOP_MINUTES']);
        $this->assertSame('15', $loaded['RUNNERDECK_QUEUE_WAIT_MINUTES']);
        $this->assertSame('90', $loaded['RUNNERDECK_LONG_JOB_MINUTES']);
        $this->assertSame('1', $loaded['RUNNERDECK_AUTO_CLEAR_WORK']);
        $this->assertSame('1', $loaded['RUNNERDECK_SYSLOG']);
        $this->assertSame('50', $loaded['RUNNERDECK_SLOT_CPU_PERCENT']);
        $this->assertSame('1024', $loaded['RUNNERDECK_SLOT_MEMORY_MAX_MIB']);
        $this->assertSame('99', $loaded['RUNNERDECK_GITHUB_APP_ID']);
        $this->assertSame('1001', $loaded['RUNNERDECK_GITHUB_APP_INSTALLATION_ID']);
        $this->assertSame('1', $loaded['RUNNERDECK_BUNDLE_SNAPSHOT']);
        $this->assertSame('1', $loaded['RUNNERDECK_BUNDLE_SNAPSHOT_SECRETS']);
        $this->assertSame('14', $loaded['RUNNERDECK_LOG_RETENTION_DAYS']);
        $this->assertSame(str_repeat('ab', 32), $loaded['RUNNERDECK_AGENT_SHA']);
    }

    #[RunInSeparateProcess]
    public function testSaveSettingsActionRejectsBadLogRetentionAndAgentSha(): void
    {
        $days = \RunnerDeck\Http\Action\SaveSettingsAction::save([
            'scope' => 'org',
            'org' => 'acme',
            'log_retention_days' => '0',
        ]);
        $this->assertFalse($days['ok']);
        $this->assertStringContainsString('log retention', $days['message']);
        $sha = \RunnerDeck\Http\Action\SaveSettingsAction::save([
            'scope' => 'org',
            'org' => 'acme',
            'agent_sha' => 'deadbeef',
        ]);
        $this->assertFalse($sha['ok']);
        $this->assertStringContainsString('agent SHA', $sha['message']);
    }

    #[RunInSeparateProcess]
    public function testSaveSettingsActionStoresMinFreeHeadroom(): void
    {
        $result = \RunnerDeck\Http\Action\SaveSettingsAction::save([
            'scope' => 'org',
            'org' => 'acme',
            'min_free_disk_gib' => '2',
            'min_free_ram_mib' => '512',
        ]);
        $this->assertTrue($result['ok']);
        $loaded = \RunnerDeck\Core\Settings::load();
        $this->assertSame('2', $loaded['RUNNERDECK_MIN_FREE_DISK_GIB']);
        $this->assertSame('512', $loaded['RUNNERDECK_MIN_FREE_RAM_MIB']);
    }

    #[RunInSeparateProcess]
    public function testSaveThrowsWhenPathIsUnwritable(): void
    {
        putenv('RUNNERDECK_SETTINGS_FILE=/dev/null/runnerdeck-unwritable/settings.json');

        $this->expectException(RuntimeException::class);
        \RunnerDeck\Core\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'my-org']);
    }
}
