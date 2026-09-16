<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ProcessControlTest extends TestCase
{
    private string $dir;

    /** @var list<int> */
    private array $killed = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-pc-test-' . uniqid();
        mkdir($this->dir, 0755, true);
        putenv('RUNNERDECK_POOL_DIR=' . dirname($this->dir));
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');

        \RunnerPool::fakeLiveListeners([]);
        \RunnerPool::fakeCheckProcess(fn () => [false, null]);
        \ProcessControl::fakeKill(function (int $pid): bool {
            $this->killed[] = $pid;
            return true;
        });
    }

    protected function tearDown(): void
    {
        \Shell::fake(null);
        \RunnerPool::fakeLiveListeners(null);
        \RunnerPool::fakeCheckProcess(null);
        \ProcessControl::fakeKill(null);
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function runner(bool $configured = true): \RunnerInfo
    {
        return new \RunnerInfo(
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
        \RunnerPool::fakeCheckProcess(fn () => [true, 4242]);
        $spawned = false;
        \Shell::fake(function () use (&$spawned): array {
            $spawned = true;
            return ['code' => 0, 'stdout' => '1', 'stderr' => ''];
        });

        $result = \ProcessControl::startIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 already running (pid 4242)', $result['message']);
        $this->assertFalse($spawned);
        $this->assertFileDoesNotExist($this->dir . '/runner.pid');
    }

    #[RunInSeparateProcess]
    public function testStartRewritesPidfileFromLiveListener(): void
    {
        \RunnerPool::fakeLiveListeners([$this->dir => 99]);

        $result = \ProcessControl::startIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 already running (pid 99)', $result['message']);
        $this->assertSame('99', trim((string) file_get_contents($this->dir . '/runner.pid')));
    }

    #[RunInSeparateProcess]
    public function testStartWritesPidfileFromSpawnStdout(): void
    {
        \Shell::fake(function (array $cmd): array {
            $this->assertSame('bash', $cmd[0]);
            $this->assertStringContainsString('nohup ./run.sh', $cmd[2]);
            return ['code' => 0, 'stdout' => "5555\n", 'stderr' => ''];
        });

        $result = \ProcessControl::startIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 started (pid 5555)', $result['message']);
        $this->assertSame('5555', trim((string) file_get_contents($this->dir . '/runner.pid')));
    }

    #[RunInSeparateProcess]
    public function testStartFailsWhenSpawnReturnsNoPid(): void
    {
        \Shell::fake(fn () => ['code' => 1, 'stdout' => '', 'stderr' => 'nohup: failed']);

        $result = \ProcessControl::startIndividual($this->runner());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed to start runner-1', $result['message']);
        $this->assertStringContainsString('nohup: failed', $result['message']);
        $this->assertFileDoesNotExist($this->dir . '/runner.pid');
    }

    #[RunInSeparateProcess]
    public function testStartUnconfiguredFailsClosedWhenProvisionFails(): void
    {
        \Shell::fake(fn () => ['code' => 22, 'stdout' => '', 'stderr' => '404']);

        $result = \ProcessControl::startIndividual($this->runner(configured: false));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed to list runner downloads', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testStopIsNoopWhenNothingIsRunning(): void
    {
        file_put_contents($this->dir . '/runner.pid', '123');

        $result = \ProcessControl::stopIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 not running', $result['message']);
        $this->assertSame([], $this->killed);
        $this->assertFileExists($this->dir . '/runner.pid');
    }

    #[RunInSeparateProcess]
    public function testStopSignalsPidAndRemovesPidfile(): void
    {
        file_put_contents($this->dir . '/runner.pid', '777');
        \RunnerPool::fakeCheckProcess(fn () => [true, 777]);

        $result = \ProcessControl::stopIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 stopped (pid 777)', $result['message']);
        $this->assertSame([777], $this->killed);
        $this->assertFileDoesNotExist($this->dir . '/runner.pid');
    }

    #[RunInSeparateProcess]
    public function testStopUsesLiveListenerWhenPidfileIsStale(): void
    {
        \RunnerPool::fakeLiveListeners([$this->dir => 888]);

        $result = \ProcessControl::stopIndividual($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 stopped (pid 888)', $result['message']);
        $this->assertSame([888], $this->killed);
    }

    #[RunInSeparateProcess]
    public function testDeleteUnconfiguredRemovesDirectory(): void
    {
        file_put_contents($this->dir . '/runner.log', 'log');
        \Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'rm') {
                exec('rm -rf ' . escapeshellarg($cmd[2]));
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = \ProcessControl::deleteRunner($this->runner(configured: false));

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 deleted', $result['message']);
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    #[RunInSeparateProcess]
    public function testDeleteConfiguredDeregistersThenRemovesFiles(): void
    {
        file_put_contents($this->dir . '/.runner', '{"agentName":"acme-1"}');
        file_put_contents($this->dir . '/.credentials', 'secret');
        $deletedGhId = null;
        \Shell::fake(function (array $cmd) use (&$deletedGhId): array {
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

        $result = \ProcessControl::deleteRunner($this->runner());

        $this->assertTrue($result['ok']);
        $this->assertSame(17, $deletedGhId);
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    #[RunInSeparateProcess]
    public function testBulkStopJoinsMessages(): void
    {
        $result = \ProcessControl::bulkStop([$this->runner()]);

        $this->assertTrue($result['ok']);
        $this->assertSame('runner-1 not running', $result['message']);
    }
}
