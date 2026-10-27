<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HostBundleSnapshotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runnerdeck-snap-' . uniqid();
        mkdir($this->root . '/storage', 0700, true);
        mkdir($this->root . '/pool/runner-1', 0755, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->root . '/storage/settings.json');
        putenv('RUNNERDECK_HISTORY_FILE=' . $this->root . '/storage/db/history.sqlite');
        putenv('RUNNERDECK_POOL_DIR=' . $this->root . '/pool');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT_SECRETS');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [false, null]);
        \RunnerDeck\Core\HostBundleSnapshot::fakeNow(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Core\HostBundleSnapshot::fakeNow(null);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_HISTORY_FILE');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT_SECRETS');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[RunInSeparateProcess]
    public function testTickIsNoopWhenDisabled(): void
    {
        \RunnerDeck\Core\HostBundleSnapshot::tick();
        $this->assertDirectoryDoesNotExist($this->root . '/storage/bundles');
    }

    #[RunInSeparateProcess]
    public function testTickWritesUtcDayWithoutWebhook(): void
    {
        \RunnerDeck\Core\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'acme',
            'RUNNERDECK_AUTH_TOTP_SECRET' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'RUNNERDECK_CRASH_WEBHOOK_URL' => 'https://example.com/hook',
        ]);
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $t = 1_700_000_000;
        \RunnerDeck\Core\HostBundleSnapshot::fakeNow(fn () => $t);
        \RunnerDeck\Core\HostBundleSnapshot::tick();
        $day = gmdate('Y-m-d', $t);
        $path = $this->root . '/storage/bundles/host-' . $day . '.json';
        $this->assertFileExists($path);
        $raw = (string) file_get_contents($path);
        $this->assertStringNotContainsString('example.com/hook', $raw);
        $this->assertStringNotContainsString('GEZDGNBVGY3TQOJQ', $raw);
        $mtime = filemtime($path);
        \RunnerDeck\Core\HostBundleSnapshot::tick();
        $this->assertSame($mtime, filemtime($path), 'same UTC day must not rewrite');
    }

    #[RunInSeparateProcess]
    public function testTickCanIncludeWebhookWhenOptedIn(): void
    {
        \RunnerDeck\Core\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'acme',
            'RUNNERDECK_CRASH_WEBHOOK_URL' => 'https://example.com/hook',
        ]);
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT_SECRETS=1');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        \RunnerDeck\Core\HostBundleSnapshot::fakeNow(fn () => 1_700_000_000);
        \RunnerDeck\Core\HostBundleSnapshot::tick();
        $day = gmdate('Y-m-d', 1_700_000_000);
        $raw = (string) file_get_contents($this->root . '/storage/bundles/host-' . $day . '.json');
        $this->assertStringContainsString('example.com/hook', $raw);
    }

    #[RunInSeparateProcess]
    public function testPruneKeepsSevenDays(): void
    {
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        $dir = $this->root . '/storage/bundles';
        mkdir($dir, 0700, true);
        for ($i = 1; $i <= 8; $i++) {
            $day = sprintf('2026-01-%02d', $i);
            file_put_contents($dir . '/host-' . $day . '.json', '{}');
        }
        \RunnerDeck\Core\HostBundleSnapshot::fakeNow(fn () => strtotime('2026-01-09 UTC'));
        \RunnerDeck\Core\HostBundleSnapshot::tick();
        $files = glob($dir . '/host-*.json') ?: [];
        $this->assertCount(7, $files);
        $this->assertFileDoesNotExist($dir . '/host-2026-01-01.json');
        $this->assertFileExists($dir . '/host-2026-01-09.json');
    }

    #[RunInSeparateProcess]
    public function testListAndDeleteRejectPathTraversal(): void
    {
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        \RunnerDeck\Core\HostBundleSnapshot::fakeNow(fn () => 1_700_000_000);
        \RunnerDeck\Core\HostBundleSnapshot::tick();
        $day = gmdate('Y-m-d', 1_700_000_000);
        $rows = \RunnerDeck\Core\HostBundleSnapshot::list();
        $this->assertSame($day, $rows[0]['day']);
        $this->assertNotNull(\RunnerDeck\Core\HostBundleSnapshot::pathFor($day));
        $this->assertNull(\RunnerDeck\Core\HostBundleSnapshot::pathFor('../settings'));
        $this->assertFalse(\RunnerDeck\Core\HostBundleSnapshot::delete('../settings'));
        $this->assertTrue(\RunnerDeck\Core\HostBundleSnapshot::delete($day));
        $this->assertSame([], \RunnerDeck\Core\HostBundleSnapshot::list());
    }
}
