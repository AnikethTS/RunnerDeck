<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AutoRestartTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-ar-' . uniqid() . '/runner-1';
        mkdir($this->dir, 0755, true);
        putenv('RUNNERDECK_POOL_DIR=' . dirname($this->dir));
        putenv('RUNNERDECK_SETTINGS_FILE=' . dirname($this->dir) . '/storage/settings.json');
        mkdir(dirname($this->dir) . '/storage', 0700, true);
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [false, null]);
        \RunnerDeck\Host\HostPressure::fakeDisk(
            fn () => [50 * 1024 * 1024 * 1024, 100 * 1024 * 1024 * 1024]
        );
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 8 * 1024 * 1024);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Core\Shell::fake(null);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(null);
        \RunnerDeck\Host\HostPressure::fakeDisk(null);
        \RunnerDeck\Host\HostPressure::fakeRam(null);
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_AUTO_CLEAR_WORK');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT');
        putenv('RUNNERDECK_HISTORY_FILE');
        \RunnerDeck\Core\HostBundleSnapshot::fakeNow(null);
        exec('rm -rf ' . escapeshellarg(dirname($this->dir)));
    }

    #[RunInSeparateProcess]
    public function testTickIsNoopWhenAutoRestartIsOff(): void
    {
        $this->assertSame([], \RunnerDeck\Pool\AutoRestart::tick());
        $this->assertFileExists(dirname($this->dir) . '/storage/watch_heartbeat.json');
    }

    #[RunInSeparateProcess]
    public function testStartFlaggedStartsOnlyMarkedRunners(): void
    {
        $spawned = [];
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$spawned): array {
            $spawned[] = $cmd;
            return ['code' => 0, 'stdout' => "4242\n", 'stderr' => ''];
        });

        $info = new \RunnerDeck\Pool\RunnerInfo(
            id: 'runner-1',
            dir: $this->dir,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );
        $started = \RunnerDeck\Pool\AutoRestart::startFlagged([
            array_merge($info->toArray(), ['should_auto_restart' => true]),
        ]);

        $this->assertCount(1, $started);
        $this->assertTrue($started[0]['ok']);
        $this->assertNotSame([], $spawned);
        $this->assertFileExists($this->dir . '/runner.pid');
    }

    #[RunInSeparateProcess]
    public function testStartFlaggedSkipsPausedRunners(): void
    {
        $spawned = [];
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$spawned): array {
            $spawned[] = $cmd;
            return ['code' => 0, 'stdout' => "4242\n", 'stderr' => ''];
        });

        $info = new \RunnerDeck\Pool\RunnerInfo(
            id: 'runner-1',
            dir: $this->dir,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );
        $started = \RunnerDeck\Pool\AutoRestart::startFlagged([
            array_merge($info->toArray(), [
                'should_auto_restart' => true,
                'auto_restart_paused' => true,
            ]),
        ]);

        $this->assertSame([], $started);
        $this->assertSame([], $spawned);
    }

    #[RunInSeparateProcess]
    public function testScaleToQueueHonorsPoolCap(): void
    {
        putenv('RUNNERDECK_POOL_CAP=1');
        $pool = dirname($this->dir);
        mkdir($pool . '/runner-2', 0755, true);
        file_put_contents($this->dir . '/.runner', json_encode(['agentName' => 'acme-1']));
        file_put_contents($pool . '/runner-2/.runner', json_encode(['agentName' => 'acme-2']));
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(function (string $pidFile): array {
            if (!is_file($pidFile)) {
                return [false, null];
            }
            return [true, (int) file_get_contents($pidFile)];
        });
        \RunnerDeck\Core\Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'bash') {
                return ['code' => 0, 'stdout' => "4242\n", 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $rows = [];
        foreach (['runner-1' => $this->dir, 'runner-2' => $pool . '/runner-2'] as $id => $dir) {
            $rows[] = (new \RunnerDeck\Pool\RunnerInfo(
                id: $id,
                dir: $dir,
                configured: true,
                agentName: $id === 'runner-1' ? 'acme-1' : 'acme-2',
                localRunning: false,
                pid: null,
                logTail: [],
            ))->toArray();
        }
        $out = \RunnerDeck\Pool\AutoRestart::scaleToQueue($rows, 5);
        $ok = array_values(array_filter($out, fn ($row) => !empty($row['ok'])));
        $this->assertCount(1, $ok);
        putenv('RUNNERDECK_POOL_CAP');
    }

    #[RunInSeparateProcess]
    public function testIdleStopStopsDueRunningSlot(): void
    {
        $storage = dirname($this->dir) . '/storage';
        putenv('RUNNERDECK_SETTINGS_FILE=' . $storage . '/settings.json');
        putenv('RUNNERDECK_IDLE_STOP_MINUTES=1');
        file_put_contents($storage . '/idle_state.json', json_encode(['runner-1' => 1]));
        file_put_contents($this->dir . '/.runner', json_encode(['agentName' => 'acme-1']));
        file_put_contents($this->dir . '/runner.pid', '99');
        \RunnerDeck\Pool\IdleState::fakeNow(fn () => 120);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(function (string $pidFile): array {
            if (!is_file($pidFile)) {
                return [false, null];
            }
            return [true, 99];
        });
        \RunnerDeck\Pool\ProcessControl::fakeKill(fn () => true);
        \RunnerDeck\Pool\ProcessControl::fakeAlive(fn () => false);
        \RunnerDeck\Pool\ProcessControl::fakeTermWait(0);
        \RunnerDeck\Core\Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners') && str_contains($joined, '--paginate')) {
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

        putenv('RUNNERDECK_AUTO_CLEAR_WORK=1');
        mkdir($this->dir . '/_work', 0755, true);
        $row = (new \RunnerDeck\Pool\RunnerInfo(
            id: 'runner-1',
            dir: $this->dir,
            configured: true,
            agentName: 'acme-1',
            localRunning: true,
            pid: 99,
            logTail: [],
        ))->toArray();
        $out = \RunnerDeck\Pool\AutoRestart::idleStop([$row]);
        $this->assertNotSame([], $out);
        $this->assertTrue($out[0]['ok']);
        $this->assertDirectoryDoesNotExist($this->dir . '/_work');
        \RunnerDeck\Pool\IdleState::fakeNow(null);
        \RunnerDeck\Pool\ProcessControl::fakeKill(null);
        \RunnerDeck\Pool\ProcessControl::fakeAlive(null);
        \RunnerDeck\Pool\ProcessControl::fakeTermWait(null);
        putenv('RUNNERDECK_IDLE_STOP_MINUTES');
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    #[RunInSeparateProcess]
    public function testTickWritesBundleSnapshotDuringMaintenance(): void
    {
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        putenv('RUNNERDECK_HISTORY_FILE=' . dirname($this->dir) . '/storage/db/history.sqlite');
        \RunnerDeck\Host\HostMode::restore(true, false);
        \RunnerDeck\Core\HostBundleSnapshot::fakeNow(fn () => 1_700_000_000);
        $this->assertSame([], \RunnerDeck\Pool\AutoRestart::tick());
        $day = gmdate('Y-m-d', 1_700_000_000);
        $this->assertFileExists(dirname($this->dir) . '/storage/bundles/host-' . $day . '.json');
        \RunnerDeck\Host\HostMode::restore(false, false);
    }
}
