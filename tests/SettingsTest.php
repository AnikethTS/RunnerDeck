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
        putenv('RUNNERDECK_CHECK_UPDATES');
        @unlink($this->settingsFile);
        @rmdir(dirname($this->settingsFile));
    }

    #[RunInSeparateProcess]
    public function testLoadReturnsEmptyArrayWhenFileMissing(): void
    {
        $this->assertSame([], \RunnerDeck\Settings::load());
    }

    #[RunInSeparateProcess]
    public function testIsConfiguredFalseWhenUnset(): void
    {
        $this->assertFalse(\RunnerDeck\Settings::isConfigured());
    }

    #[RunInSeparateProcess]
    public function testSaveThenLoadRoundTrips(): void
    {
        \RunnerDeck\Settings::save([
            'RUNNERDECK_SCOPE' => 'repo',
            'RUNNERDECK_REPO' => 'AnikethTS/RunnerDeck',
            'RUNNERDECK_ORG' => '',
            'RUNNERDECK_LABEL' => 'my-label',
        ]);

        $loaded = \RunnerDeck\Settings::load();

        $this->assertSame('repo', $loaded['RUNNERDECK_SCOPE']);
        $this->assertSame('AnikethTS/RunnerDeck', $loaded['RUNNERDECK_REPO']);
        $this->assertSame('my-label', $loaded['RUNNERDECK_LABEL']);
        $this->assertArrayNotHasKey('RUNNERDECK_ORG', $loaded, 'empty values should be dropped, not saved as blanks');
    }

    #[RunInSeparateProcess]
    public function testIsConfiguredTrueAfterSavingOrgScope(): void
    {
        \RunnerDeck\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'my-org']);
        $this->assertTrue(\RunnerDeck\Settings::isConfigured());
    }

    #[RunInSeparateProcess]
    public function testIsConfiguredFalseWhenRepoScopeMissingRepo(): void
    {
        \RunnerDeck\Settings::save(['RUNNERDECK_SCOPE' => 'repo']);
        $this->assertFalse(\RunnerDeck\Settings::isConfigured());
    }

    #[RunInSeparateProcess]
    public function testApplyToEnvDoesNotOverrideRealEnvVar(): void
    {
        \RunnerDeck\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'from-settings-file']);
        putenv('RUNNERDECK_ORG=from-real-env');

        \RunnerDeck\Settings::applyToEnv();

        $this->assertSame('from-real-env', getenv('RUNNERDECK_ORG'));
    }

    #[RunInSeparateProcess]
    public function testApplyToEnvSetsUnsetKeysFromSavedSettings(): void
    {
        \RunnerDeck\Settings::save(['RUNNERDECK_SCOPE' => 'repo', 'RUNNERDECK_REPO' => 'owner/repo']);

        \RunnerDeck\Settings::applyToEnv();

        $this->assertSame('repo', getenv('RUNNERDECK_SCOPE'));
        $this->assertSame('owner/repo', getenv('RUNNERDECK_REPO'));
    }

    #[RunInSeparateProcess]
    public function testSaveThenLoadRoundTripsCheckUpdates(): void
    {
        \RunnerDeck\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'my-org',
            'RUNNERDECK_CHECK_UPDATES' => '1',
        ]);

        $loaded = \RunnerDeck\Settings::load();

        $this->assertSame('1', $loaded['RUNNERDECK_CHECK_UPDATES']);
    }

    #[RunInSeparateProcess]
    public function testSaveThrowsWhenPathIsUnwritable(): void
    {
        putenv('RUNNERDECK_SETTINGS_FILE=/nonexistent-root-only-path/settings.json');

        $this->expectException(RuntimeException::class);
        \RunnerDeck\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'my-org']);
    }
}
