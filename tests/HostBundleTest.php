<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HostBundleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runnerdeck-bundle-' . uniqid();
        mkdir($this->root . '/storage', 0700, true);
        mkdir($this->root . '/pool/runner-1', 0755, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->root . '/storage/settings.json');
        putenv('RUNNERDECK_HISTORY_FILE=' . $this->root . '/storage/db/history.sqlite');
        putenv('RUNNERDECK_POOL_DIR=' . $this->root . '/pool');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [false, null]);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Core\Shell::fake(null);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_HISTORY_FILE');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[RunInSeparateProcess]
    public function testExportOmitsTotpAndWebhookByDefault(): void
    {
        \RunnerDeck\Core\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'acme',
            'RUNNERDECK_AUTH_TOTP_SECRET' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'RUNNERDECK_CRASH_WEBHOOK_URL' => 'https://example.com/hook',
        ]);
        \RunnerDeck\Pool\SlotFlags::setEphemeral($this->root . '/pool/runner-1', true);
        \RunnerDeck\Pool\SlotFlags::setExtraLabels($this->root . '/pool/runner-1', ['gpu']);
        \RunnerDeck\Host\HostMode::restore(true, false);
        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');

        $bundle = \RunnerDeck\Core\HostBundle::export();
        $this->assertTrue(\RunnerDeck\Core\HostBundle::isBundle($bundle));
        $this->assertSame('acme', $bundle['settings']['RUNNERDECK_ORG']);
        $this->assertArrayNotHasKey('RUNNERDECK_AUTH_TOTP_SECRET', $bundle['settings']);
        $this->assertArrayNotHasKey('RUNNERDECK_CRASH_WEBHOOK_URL', $bundle['settings']);
        $this->assertArrayNotHasKey('secrets', $bundle);
        $this->assertTrue($bundle['host_mode']['maintenance']);
        $this->assertNotSame([], $bundle['crash_events']);
        $this->assertTrue($bundle['slots'][0]['ephemeral']);
        $this->assertSame(['gpu'], $bundle['slots'][0]['extra_labels']);
        $encoded = json_encode($bundle);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('example.com/hook', $encoded);
        $this->assertStringNotContainsString('GEZDGNBVGY3TQOJQ', $encoded);
    }

    #[RunInSeparateProcess]
    public function testExportWithSecretsIncludesWebhookNotTotp(): void
    {
        \RunnerDeck\Core\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'acme',
            'RUNNERDECK_AUTH_TOTP_SECRET' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'RUNNERDECK_CRASH_WEBHOOK_URL' => 'https://example.com/hook',
        ]);
        $bundle = \RunnerDeck\Core\HostBundle::export(true);
        $this->assertSame(
            'https://example.com/hook',
            $bundle['secrets']['RUNNERDECK_CRASH_WEBHOOK_URL']
        );
        $this->assertArrayNotHasKey('RUNNERDECK_AUTH_TOTP_SECRET', $bundle['settings']);
        $json = json_encode($bundle);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('GEZDGNBVGY3TQOJQ', $json);
    }

    #[RunInSeparateProcess]
    public function testImportRestoresHostWithoutWebhookAndKeepsLogin(): void
    {
        \RunnerDeck\Core\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'old',
            'RUNNERDECK_AUTH_TOTP_SECRET' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'RUNNERDECK_CRASH_WEBHOOK_URL' => 'https://keep.example/hook',
        ]);
        $posts = 0;
        \RunnerDeck\Core\Shell::fake(function () use (&$posts): array {
            $posts++;
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $result = \RunnerDeck\Core\HostBundle::import([
            'kind' => \RunnerDeck\Core\HostBundle::KIND,
            'version' => 1,
            'settings' => [
                'RUNNERDECK_SCOPE' => 'org',
                'RUNNERDECK_ORG' => 'neworg',
                'RUNNERDECK_AUTH_TOTP_SECRET' => 'should-not-apply',
            ],
            'host_mode' => ['maintenance' => true, 'reboot_ready' => true],
            'crash_state' => [
                'runner-1' => [
                    'autoRestartPaused' => true,
                    'flagged' => true,
                    'wasRunning' => true,
                    'attempts' => 2,
                ],
            ],
            'agent_roster' => ['ghost-runner'],
            'slots' => [
                [
                    'id' => 'runner-2',
                    'ephemeral' => true,
                    'extra_labels' => ['linux'],
                ],
            ],
            'crash_events' => [
                ['ts' => time() - 60, 'runner_id' => 'runner-1', 'agent_name' => 'acme-1'],
            ],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $posts);
        $loaded = \RunnerDeck\Core\Settings::load();
        $this->assertSame('neworg', $loaded['RUNNERDECK_ORG']);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $loaded['RUNNERDECK_AUTH_TOTP_SECRET']);
        $this->assertSame('https://keep.example/hook', $loaded['RUNNERDECK_CRASH_WEBHOOK_URL']);
        $this->assertTrue(\RunnerDeck\Host\HostMode::isMaintenance());
        $this->assertFalse(\RunnerDeck\Host\HostMode::isRebootReady());
        $state = \RunnerDeck\Pool\CrashState::export();
        $this->assertTrue($state['runner-1']['autoRestartPaused']);
        $this->assertTrue($state['runner-1']['flagged']);
        $this->assertFalse($state['runner-1']['wasRunning']);
        $this->assertSame(['ghost-runner'], \RunnerDeck\GitHub\AgentRoster::names());
        $this->assertTrue(\RunnerDeck\Pool\SlotFlags::isEphemeral($this->root . '/pool/runner-2'));
        $this->assertSame(['linux'], \RunnerDeck\Pool\SlotFlags::extraLabels($this->root . '/pool/runner-2'));
        $this->assertSame(1, \RunnerDeck\Pool\CrashHistory::countsByRunner()['runner-1']);
        $this->assertStringNotContainsString('keep.example', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testImportRejectsUnknownVersion(): void
    {
        $result = \RunnerDeck\Core\HostBundle::import([
            'kind' => \RunnerDeck\Core\HostBundle::KIND,
            'version' => 99,
        ]);
        $this->assertFalse($result['ok']);
    }
}
