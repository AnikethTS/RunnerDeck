<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\ConfigCommand;
use RunnerDeck\Configuration\Settings;

final class ConfigCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/rd-config-' . uniqid();
        mkdir($dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $dir . '/settings.json');
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    public function testFriendlyNamesDropThePrefixAndUseHyphens(): void
    {
        $this->assertSame('drain-timeout', ConfigCommand::friendly('RUNNERDECK_DRAIN_TIMEOUT'));
        $this->assertContains('github-app-installation-id', ConfigCommand::names());
        $this->assertNotContains('auth-totp-secret', ConfigCommand::names());
    }

    public function testSetupSavesOrgAndRepoScopes(): void
    {
        $this->assertTrue(ConfigCommand::setup('org', 'acme')['ok']);
        $this->assertSame('acme', Settings::load()['RUNNERDECK_ORG']);
        $this->assertTrue(ConfigCommand::setup('REPO', 'acme/widgets')['ok']);
        $saved = Settings::load();
        $this->assertSame('repo', $saved['RUNNERDECK_SCOPE']);
        $this->assertSame('acme/widgets', $saved['RUNNERDECK_REPO']);
        $this->assertArrayNotHasKey('RUNNERDECK_ORG', $saved);
    }

    public function testSetupRefusesBadScopeAndEmptyTarget(): void
    {
        $this->assertFalse(ConfigCommand::setup('team', 'x')['ok']);
        $this->assertFalse(ConfigCommand::setup('org', '')['ok']);
        $this->assertFalse(ConfigCommand::setup('repo', 'plain')['ok']);
    }

    public function testChangingOneSettingKeepsTheOthers(): void
    {
        ConfigCommand::setup('org', 'acme');
        ConfigCommand::set('pool-cap', '8');
        ConfigCommand::set('drain-timeout', '90');
        ConfigCommand::set('auto-restart', 'yes');
        $this->assertSame(
            ['auto-restart' => '1', 'drain-timeout' => '90', 'org' => 'acme', 'pool-cap' => '8', 'scope' => 'org'],
            ConfigCommand::all()
        );
        ConfigCommand::set('auto-restart', 'off');
        $this->assertArrayNotHasKey('auto-restart', ConfigCommand::all());
        $this->assertSame('8', ConfigCommand::all()['pool-cap']);
    }

    public function testUnsetClearsASetting(): void
    {
        ConfigCommand::setup('org', 'acme');
        ConfigCommand::set('pool-cap', '8');
        $this->assertTrue(ConfigCommand::unset('pool-cap')['ok']);
        $this->assertArrayNotHasKey('pool-cap', ConfigCommand::all());
    }

    public function testInvalidValuesAreRejectedByTheSameRulesAsTheDashboard(): void
    {
        ConfigCommand::setup('org', 'acme');
        $this->assertStringContainsString('pool cap', ConfigCommand::set('pool-cap', '99')['message']);
        $this->assertArrayNotHasKey('pool-cap', ConfigCommand::all());
    }

    public function testTheTotpSecretIsNeverExposedOrLost(): void
    {
        ConfigCommand::setup('org', 'acme');
        $path = (string) getenv('RUNNERDECK_SETTINGS_FILE');
        $saved = json_decode((string) file_get_contents($path), true);
        $saved['RUNNERDECK_AUTH_TOTP_SECRET'] = 'JBSWY3DPEHPK3PXP';
        file_put_contents($path, json_encode($saved));
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        try {
            ConfigCommand::set('pool-cap', '4');
            $this->assertArrayNotHasKey('auth-totp-secret', ConfigCommand::all());
            $this->assertSame('JBSWY3DPEHPK3PXP', Settings::load()['RUNNERDECK_AUTH_TOTP_SECRET'] ?? '');
        } finally {
            putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        }
    }
}
