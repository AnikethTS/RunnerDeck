<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ProcessControlTest extends TestCase
{
    private string $dir;

    private string $storageDir;

    /** @var list<int> */
    private array $killed = [];

    /** @var list<int> */
    private array $signals = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-pc-test-' . uniqid();
        mkdir($this->dir, 0755, true);
        $this->storageDir = sys_get_temp_dir() . '/runnerdeck-pc-storage-' . uniqid();
        mkdir($this->storageDir, 0700, true);
        putenv('RUNNERDECK_POOL_DIR=' . dirname($this->dir));
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->storageDir . '/settings.json');

        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [false, null]);
        \RunnerDeck\Pool\ProcessControl::fakeKill(function (int $pid, int $signal): bool {
            $this->killed[] = $pid;
            $this->signals[] = $signal;
            return true;
        });
        \RunnerDeck\Pool\ProcessControl::fakeTermWait(0);
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
        \RunnerDeck\Pool\ProcessControl::fakeKill(null);
        \RunnerDeck\Pool\ProcessControl::fakeAlive(null);
        \RunnerDeck\Pool\ProcessControl::fakeTermWait(null);
        \RunnerDeck\Host\HostPressure::fakeDisk(null);
        \RunnerDeck\Host\HostPressure::fakeRam(null);
        \RunnerDeck\Host\SlotCgroup::fakeRoot(null);
        putenv('RUNNERDECK_SLOT_CPU_PERCENT');
        putenv('RUNNERDECK_SLOT_MEMORY_MAX_MIB');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_AUTO_CLEAR_WORK');
        exec('rm -rf ' . escapeshellarg($this->dir) . ' ' . escapeshellarg($this->dir . '.deleting')
            . ' ' . escapeshellarg($this->storageDir));
    }

    private function lastLog(): array
    {
        $path = $this->storageDir . '/runnerdeck.log';
        $this->assertFileExists($path);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        $decoded = json_decode($lines[count($lines) - 1], true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    private function runner(bool $configured = true): \RunnerDeck\Pool\RunnerInfo
    {
        return new \RunnerDeck\Pool\RunnerInfo(
            id: 'runner-1',
            dir: $this->dir,
            configured: $configured,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );
    }

    #[RunInSeparateProcess]
    public function testStartSkipsSpawnWhenPidfileIsLive(): void
    {
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [true, 4242]);
        $spawned = false;
        \RunnerDeck\Core\Shell::fake(function () use (&$spawned): array {
            $spawned = true;
            return ['code' => 0, 'stdout' => '1', 'stderr' => ''];
        });

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 already running (pid 4242)', $result['message']);
        $this->assertFalse($spawned);
        $this->assertFileDoesNotExist($this->dir . '/runner.pid');
        $this->assertFileDoesNotExist($this->storageDir . '/runnerdeck.log');
    }

    #[RunInSeparateProcess]
    public function testStartRewritesPidfileFromLiveListener(): void
    {
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([$this->dir => 99]);

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 already running (pid 99)', $result['message']);
        $this->assertSame('99', trim((string) file_get_contents($this->dir . '/runner.pid')));
        $this->assertFileDoesNotExist($this->storageDir . '/runnerdeck.log');
    }

    #[RunInSeparateProcess]
    public function testStartWritesPidfileFromSpawnStdout(): void
    {
        \RunnerDeck\Core\Shell::fake(function (array $cmd): array {
            $this->assertSame('bash', $cmd[0]);
            $this->assertStringContainsString('nohup ./run.sh', $cmd[2]);
            return ['code' => 0, 'stdout' => "5555\n", 'stderr' => ''];
        });

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 started (pid 5555)', $result['message']);
        $this->assertSame('5555', trim((string) file_get_contents($this->dir . '/runner.pid')));
        $this->assertSame('info', $this->lastLog()['level']);
        $this->assertSame('process.start', $this->lastLog()['action']);
    }

    #[RunInSeparateProcess]
    public function testStartAppliesCgroupLimitsWhenConfigured(): void
    {
        $cg = $this->storageDir . '/cgroup';
        mkdir($cg, 0755, true);
        file_put_contents($cg . '/cgroup.controllers', "cpu memory\n");
        \RunnerDeck\Host\SlotCgroup::fakeRoot($cg);
        putenv('RUNNERDECK_SLOT_CPU_PERCENT=50');
        putenv('RUNNERDECK_SLOT_MEMORY_MAX_MIB=256');
        \RunnerDeck\Core\Shell::fake(fn () => ['code' => 0, 'stdout' => "5555\n", 'stderr' => '']);

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $slot = $cg . '/runnerdeck/runner-1';
        $this->assertSame('50000 100000', trim((string) file_get_contents($slot . '/cpu.max')));
        $this->assertSame('268435456', trim((string) file_get_contents($slot . '/memory.max')));
        $this->assertSame('5555', trim((string) file_get_contents($slot . '/cgroup.procs')));
    }

    #[RunInSeparateProcess]
    public function testStartFailsWhenSpawnReturnsNoPid(): void
    {
        \RunnerDeck\Core\Shell::fake(fn () => ['code' => 1, 'stdout' => '', 'stderr' => 'nohup: failed']);

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed to start runner-1', $result['message']);
        $this->assertStringContainsString('nohup: failed', $result['message']);
        $this->assertFileDoesNotExist($this->dir . '/runner.pid');
    }

    #[RunInSeparateProcess]
    public function testStartUnconfiguredFailsClosedWhenProvisionFails(): void
    {
        \RunnerDeck\Core\Shell::fake(fn () => ['code' => 22, 'stdout' => '', 'stderr' => '404']);

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner(configured: false));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed to list runner downloads', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testStopIsNoopWhenNothingIsRunning(): void
    {
        file_put_contents($this->dir . '/runner.pid', '123');

        $result = \RunnerDeck\Pool\ProcessControl::stopIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 not running', $result['message']);
        $this->assertSame([], $this->killed);
        $this->assertFileExists($this->dir . '/runner.pid');
        $this->assertFileDoesNotExist($this->storageDir . '/runnerdeck.log');
    }

    #[RunInSeparateProcess]
    public function testStopSignalsPidAndRemovesPidfile(): void
    {
        file_put_contents($this->dir . '/runner.pid', '777');
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [true, 777]);

        $result = \RunnerDeck\Pool\ProcessControl::stopIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 stopped (pid 777)', $result['message']);
        $this->assertSame([777], $this->killed);
        $this->assertSame([SIGTERM], $this->signals);
        $this->assertFileDoesNotExist($this->dir . '/runner.pid');
        $this->assertSame('process.stop', $this->lastLog()['action']);
        $this->assertSame('info', $this->lastLog()['level']);
    }

    #[RunInSeparateProcess]
    public function testStopUsesLiveListenerWhenPidfileIsStale(): void
    {
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([$this->dir => 888]);

        $result = \RunnerDeck\Pool\ProcessControl::stopIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 stopped (pid 888)', $result['message']);
        $this->assertSame([888], $this->killed);
        $this->assertSame([SIGTERM], $this->signals);
        $this->assertSame('process.stop', $this->lastLog()['action']);
    }

    #[RunInSeparateProcess]
    public function testDeleteUnconfiguredRemovesDirectory(): void
    {
        file_put_contents($this->dir . '/runner.log', 'log');
        \RunnerDeck\Core\Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'rm') {
                $this->assertSame($this->dir . '.deleting', $cmd[2]);
                exec('rm -rf ' . escapeshellarg($cmd[2]));
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = \RunnerDeck\Pool\ProcessControl::deleteRunner($this->runner(configured: false));

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 deleted', $result['message']);
        $this->assertDirectoryDoesNotExist($this->dir);
        $this->assertSame('process.delete', $this->lastLog()['action']);
    }

    #[RunInSeparateProcess]
    public function testDeleteConfiguredDeregistersThenRemovesFiles(): void
    {
        file_put_contents($this->dir . '/.runner', '{"agentName":"acme-1"}');
        file_put_contents($this->dir . '/.credentials', 'secret');
        $deletedGhId = null;
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$deletedGhId): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners') && !str_contains($joined, 'DELETE')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode([
                        'runners' => [
                            ['id' => 17, 'name' => 'acme-1', 'status' => 'offline', 'busy' => false, 'labels' => []],
                        ],
                    ]),
                    'stderr' => '',
                ];
            }
            if (str_contains($joined, 'DELETE') && str_contains($joined, '/actions/runners/17')) {
                $deletedGhId = 17;
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'rm') {
                exec('rm -rf ' . escapeshellarg($cmd[2]));
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = \RunnerDeck\Pool\ProcessControl::deleteRunner($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame(17, $deletedGhId);
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    #[RunInSeparateProcess]
    public function testDeleteRenamesSlotAwayBeforeRmSoATimeoutCannotGhostIt(): void
    {
        mkdir($this->dir . '/externals', 0755, true);
        file_put_contents($this->dir . '/externals/x', 'partial');
        \RunnerDeck\Core\Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'rm') {
                return [
                    'code' => -1,
                    'stdout' => '',
                    'stderr' => "[timed out after 60s, process killed]",
                ];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = \RunnerDeck\Pool\ProcessControl::deleteRunner($this->runner(configured: false));

        $this->assertFalse($result['ok']);
        $this->assertDirectoryDoesNotExist($this->dir);
        $this->assertDirectoryExists($this->dir . '.deleting');
        $this->assertStringContainsString('.deleting', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testBulkStopJoinsMessages(): void
    {
        $result = \RunnerDeck\Pool\ProcessControl::bulkStop([$this->runner()]);

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 not running', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testClearWorkDirRefusesWhenRunning(): void
    {
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [true, 9]);

        $result = \RunnerDeck\Pool\ProcessControl::clearWorkDir($this->runner());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('running', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testClearWorkDirRemovesWorkTreeWhenStopped(): void
    {
        mkdir($this->dir . '/_work', 0755, true);
        file_put_contents($this->dir . '/_work/x', 'job');

        $result = \RunnerDeck\Pool\ProcessControl::clearWorkDir($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertDirectoryDoesNotExist($this->dir . '/_work');
        $this->assertSame('process.clear_work', $this->lastLog()['action']);
    }

    #[RunInSeparateProcess]
    public function testMaybeClearWorkNoopsWhenDisabled(): void
    {
        mkdir($this->dir . '/_work', 0755, true);
        $this->assertSame('', \RunnerDeck\Pool\ProcessControl::maybeClearWork($this->runner()));
        $this->assertDirectoryExists($this->dir . '/_work');
    }

    #[RunInSeparateProcess]
    public function testMaybeClearWorkRemovesTreeWhenEnabledAndStopped(): void
    {
        putenv('RUNNERDECK_AUTO_CLEAR_WORK=1');
        mkdir($this->dir . '/_work', 0755, true);
        file_put_contents($this->dir . '/_work/x', 'job');
        $note = \RunnerDeck\Pool\ProcessControl::maybeClearWork($this->runner());
        $this->assertStringContainsString('runner-1', $note);
        $this->assertDirectoryDoesNotExist($this->dir . '/_work');
    }

    #[RunInSeparateProcess]
    public function testBulkStopDoesNotClearWorkWithoutDrainFlag(): void
    {
        putenv('RUNNERDECK_AUTO_CLEAR_WORK=1');
        mkdir($this->dir . '/_work', 0755, true);
        $result = \RunnerDeck\Pool\ProcessControl::bulkStop([$this->runner()]);
        $this->assertTrue($result['ok']);
        $this->assertDirectoryExists($this->dir . '/_work');
    }

    #[RunInSeparateProcess]
    public function testBulkStopAfterDrainClearsWorkWhenEnabled(): void
    {
        putenv('RUNNERDECK_AUTO_CLEAR_WORK=1');
        mkdir($this->dir . '/_work', 0755, true);
        $result = \RunnerDeck\Pool\ProcessControl::bulkStop([$this->runner()], true);
        $this->assertTrue($result['ok']);
        $this->assertDirectoryDoesNotExist($this->dir . '/_work');
    }

    #[RunInSeparateProcess]
    public function testStopSendsKillWhenProcessIgnoresTerm(): void
    {
        file_put_contents($this->dir . '/runner.pid', '777');
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [true, 777]);
        $alive = true;
        \RunnerDeck\Pool\ProcessControl::fakeAlive(function () use (&$alive): bool {
            return $alive;
        });
        \RunnerDeck\Pool\ProcessControl::fakeKill(function (int $pid, int $signal) use (&$alive): bool {
            $this->killed[] = $pid;
            $this->signals[] = $signal;
            if ($signal === SIGKILL) {
                $alive = false;
            }
            return true;
        });

        $result = \RunnerDeck\Pool\ProcessControl::stopIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame([SIGTERM, SIGKILL], $this->signals);
    }

    #[RunInSeparateProcess]
    public function testStartRefusesWhenPoolCapReached(): void
    {
        putenv('RUNNERDECK_POOL_CAP=1');
        $pool = $this->storageDir . '/pool';
        mkdir($pool, 0755, true);
        putenv('RUNNERDECK_POOL_DIR=' . $pool);
        $busy = $pool . '/runner-base';
        mkdir($busy, 0755, true);
        file_put_contents($busy . '/runner.pid', '88');
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(function (string $pidFile): array {
            return str_contains($pidFile, 'runner-base') ? [true, 88] : [false, null];
        });
        $spawned = false;
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$spawned): array {
            if (($cmd[0] ?? '') === 'bash') {
                $spawned = true;
            }
            return ['code' => 0, 'stdout' => "1\n", 'stderr' => ''];
        });

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Pool cap', $result['message']);
        $this->assertFalse($spawned);
        putenv('RUNNERDECK_POOL_CAP');
    }

    #[RunInSeparateProcess]
    public function testStartRefusesWhenHostPressureIsHigh(): void
    {
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [50 * 1024 * 1024, 20 * 1024 * 1024 * 1024]);
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB=1');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB=0');
        $spawned = false;
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$spawned): array {
            if (($cmd[0] ?? '') === 'bash') {
                $spawned = true;
            }
            return ['code' => 0, 'stdout' => "1\n", 'stderr' => ''];
        });

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('low on', $result['message']);
        $this->assertFalse($spawned);
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB');
    }

    #[RunInSeparateProcess]
    public function testStopFailsWhenProcessSurvivesKill(): void
    {
        file_put_contents($this->dir . '/runner.pid', '777');
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [true, 777]);
        \RunnerDeck\Pool\ProcessControl::fakeAlive(fn () => true);

        $result = \RunnerDeck\Pool\ProcessControl::stopIndividual($this->runner());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('still running after SIGKILL', $result['message']);
        $this->assertSame([SIGTERM, SIGKILL], $this->signals);
        $this->assertFileExists($this->dir . '/runner.pid');
    }

    #[RunInSeparateProcess]
    public function testForceIdleRefusesWhenLocalProcessIsRunning(): void
    {
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [true, 9]);
        $result = \RunnerDeck\Pool\ProcessControl::forceIdle($this->runner());
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('still running locally', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testForceIdleDeletesGithubAndLocalRegistration(): void
    {
        file_put_contents($this->dir . '/.runner', '{"agentName":"acme-1"}');
        file_put_contents($this->dir . '/.credentials', 'secret');
        file_put_contents($this->dir . '/runner.log', 'keep');
        $deletedGhId = null;
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$deletedGhId): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners') && !str_contains($joined, 'DELETE')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode([
                        'runners' => [
                            ['id' => 17, 'name' => 'acme-1', 'status' => 'online', 'busy' => true, 'labels' => []],
                        ],
                    ]),
                    'stderr' => '',
                ];
            }
            if (str_contains($joined, 'DELETE') && str_contains($joined, '/actions/runners/17')) {
                $deletedGhId = 17;
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = \RunnerDeck\Pool\ProcessControl::forceIdle($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame(17, $deletedGhId);
        $this->assertFileDoesNotExist($this->dir . '/.runner');
        $this->assertFileDoesNotExist($this->dir . '/.credentials');
        $this->assertFileExists($this->dir . '/runner.log');
        $this->assertSame('process.force_idle', $this->lastLog()['action']);
    }

    #[RunInSeparateProcess]
    public function testStartClearsRebootReady(): void
    {
        \RunnerDeck\Host\HostMode::setRebootReady(true);
        \RunnerDeck\Core\Shell::fake(function (array $cmd): array {
            $this->assertSame('bash', $cmd[0]);
            return ['code' => 0, 'stdout' => "5555\n", 'stderr' => ''];
        });

        $result = \RunnerDeck\Pool\ProcessControl::startIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertFalse(\RunnerDeck\Host\HostMode::isRebootReady());
    }
}
