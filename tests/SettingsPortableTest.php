<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SettingsPortableTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/runnerdeck-portable-' . uniqid() . '/settings.json';
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->file);
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_SETTINGS_FILE');
        $dir = dirname($this->file);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    #[RunInSeparateProcess]
    public function testExportOmitsTotpAndWebhook(): void
    {
        \RunnerDeck\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'acme',
            'RUNNERDECK_AUTH_TOTP_SECRET' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'RUNNERDECK_CRASH_WEBHOOK_URL' => 'https://example.com/hook',
            'RUNNERDECK_LABEL' => 'self-hosted-runnerdeck',
        ]);
        $out = \RunnerDeck\SettingsPortable::export();
        $this->assertSame('acme', $out['RUNNERDECK_ORG']);
        $this->assertArrayNotHasKey('RUNNERDECK_AUTH_TOTP_SECRET', $out);
        $this->assertArrayNotHasKey('RUNNERDECK_CRASH_WEBHOOK_URL', $out);
    }

    #[RunInSeparateProcess]
    public function testImportKeepsTotpAndExistingWebhook(): void
    {
        \RunnerDeck\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'acme',
            'RUNNERDECK_AUTH_TOTP_SECRET' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'RUNNERDECK_CRASH_WEBHOOK_URL' => 'https://example.com/hook',
        ]);
        $result = \RunnerDeck\SettingsPortable::import([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'other',
            'RUNNERDECK_AUTH_TOTP_SECRET' => 'should-not-apply',
        ]);
        $this->assertTrue($result['ok']);
        $loaded = \RunnerDeck\Settings::load();
        $this->assertSame('other', $loaded['RUNNERDECK_ORG']);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $loaded['RUNNERDECK_AUTH_TOTP_SECRET']);
        $this->assertSame('https://example.com/hook', $loaded['RUNNERDECK_CRASH_WEBHOOK_URL']);
    }
}
