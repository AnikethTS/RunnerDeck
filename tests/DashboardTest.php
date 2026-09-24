<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private string $root;
    private string $poolDir;
    private string $runnerDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runnerdeck-dash-test-' . uniqid();
        $this->poolDir = $this->root . '/pool';
        $this->runnerDir = $this->poolDir . '/runner-1';
        mkdir($this->runnerDir, 0755, true);
        file_put_contents($this->runnerDir . '/.runner', json_encode(['agentName' => 'acme-1']));

        putenv('RUNNERDECK_POOL_DIR=' . $this->poolDir);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->root . '/storage/settings.json');
        putenv('RUNNERDECK_HISTORY_FILE=' . $this->root . '/storage/db/history.sqlite');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        \RunnerDeck\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\RunnerPool::fakeCheckProcess(null);
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_HISTORY_FILE');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_AUTO_RESTART');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[RunInSeparateProcess]
    public function testSnapshotNeverSpawnsAProcessEvenWhenAutoRestartShouldFire(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');

        $spawned = false;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$spawned): array {
            if (($cmd[0] ?? '') === 'bash') {
                $spawned = true;
                return ['code' => 0, 'stdout' => "4242\n", 'stderr' => ''];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in'];
        });

        \RunnerDeck\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [true, 999]);
        \RunnerDeck\Dashboard::snapshot();

        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [false, null]);
        $result = \RunnerDeck\Dashboard::snapshot();

        $this->assertTrue(
            $result['runners'][0]['should_auto_restart'],
            'sanity: this poll should have flagged the crash, or the test proves nothing'
        );
        $this->assertFalse(
            $spawned,
            'action=status (GET) must never spawn a process — it has no CSRF check by design'
        );
        $this->assertIsArray($result['errors']);
    }

    #[RunInSeparateProcess]
    public function testSnapshotSkipsAuthStatusWhenListRunnersSucceeds(): void
    {
        $authCalled = false;
        $listCalled = false;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$authCalled, &$listCalled): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'auth status')) {
                $authCalled = true;
                return ['code' => 0, 'stdout' => 'logged in', 'stderr' => ''];
            }
            if (str_contains($joined, '/actions/runners') && str_contains($joined, '--paginate')) {
                $listCalled = true;
                return [
                    'code' => 0,
                    'stdout' => json_encode([
                        'id' => 1,
                        'name' => 'acme-1',
                        'status' => 'online',
                        'busy' => false,
                        'labels' => [],
                    ]),
                    'stderr' => '',
                ];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        \RunnerDeck\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [false, null]);

        $result = \RunnerDeck\Dashboard::snapshot();

        $this->assertTrue($listCalled);
        $this->assertFalse($authCalled);
        $this->assertTrue($result['health']['logged_in']);
        $this->assertTrue($result['health']['org_access_ok']);
        $this->assertSame('online', $result['runners'][0]['github']['status']);
    }

    #[RunInSeparateProcess]
    public function testSnapshotUsesAuthStatusOnlyAfterListRunnersFails(): void
    {
        $authCalled = false;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$authCalled): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'auth status')) {
                $authCalled = true;
                return ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in'];
            }
            if (str_contains($joined, '/actions/runners')) {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'HTTP 401'];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        \RunnerDeck\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [false, null]);

        $result = \RunnerDeck\Dashboard::snapshot();

        $this->assertTrue($authCalled);
        $this->assertFalse($result['health']['logged_in']);
        $this->assertFalse($result['health']['org_access_ok']);
        $this->assertSame('not logged in', $result['health']['message']);
    }

    #[RunInSeparateProcess]
    public function testSnapshotFiresCrashWebhookOnRealCrash(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');

        $webhookBody = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$webhookBody): array {
            if (($cmd[0] ?? '') === 'curl') {
                $webhookBody = $cmd[array_search('-d', $cmd, true) + 1];
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in'];
        });

        \RunnerDeck\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [true, 999]);
        \RunnerDeck\Dashboard::snapshot();

        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [false, null]);
        \RunnerDeck\Dashboard::snapshot();

        $this->assertNotNull($webhookBody, 'expected a webhook POST for the crash-loop');
        $decoded = json_decode((string) $webhookBody, true);
        $this->assertSame('runner-1', $decoded['runner']);
    }
}
