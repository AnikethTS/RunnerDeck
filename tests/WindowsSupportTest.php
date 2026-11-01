<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Core\Platform;
use RunnerDeck\Core\Shell;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\Provisioner;
use RunnerDeck\Pool\RunnerInfo;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\Pool\WindowsProcess;

final class WindowsSupportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-win-test-' . uniqid();
        mkdir($this->dir . '/runner-1', 0755, true);
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir);
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/storage/settings.json');
        Platform::fakeWindows(true);
    }

    protected function tearDown(): void
    {
        Platform::fakeWindows(null);
        Shell::fake(null);
        RunnerPool::fakeLiveListeners(null);
        ProcessControl::fakeTermWait(null);
        ProcessControl::fakeAlive(null);
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_SETTINGS_FILE');
        \RunnerDeck\Host\SlotDisk::removeTree($this->dir);
    }

    public function testRunnerScriptUsesCmdOnWindows(): void
    {
        $this->assertSame('config.cmd', Platform::runnerScript('config'));
        Platform::fakeWindows(false);
        $this->assertSame('run.sh', Platform::runnerScript('run'));
    }

    public function testPathKeyIgnoresSlashStyleAndCaseOnWindowsOnly(): void
    {
        $this->assertSame('c:/runners/runner-1', Platform::pathKey('C:\\Runners\\runner-1\\'));
        Platform::fakeWindows(false);
        $this->assertSame('C:\\Runners', Platform::pathKey('C:\\Runners'));
    }

    public function testBatchArgsRejectCmdMetacharacters(): void
    {
        $this->assertTrue(Platform::isSafeBatchArg('acme-runner_1.win'));
        $this->assertTrue(Platform::isSafeBatchArg('https://github.com/acme/repo'));
        foreach (['a&calc', 'a|b', 'a%PATH%', 'a^b', 'a"b', 'a b', 'a>b', '-x'] as $bad) {
            $this->assertFalse(Platform::isSafeBatchArg($bad), $bad);
        }
    }

    public function testParseListenersTakesRunnerDirFromExePath(): void
    {
        $json = json_encode([
            ['ProcessId' => 4120, 'ExecutablePath' => 'C:\\Runners\\runner-1\\bin\\Runner.Listener.exe'],
            ['ProcessId' => 77, 'ExecutablePath' => null],
        ]);

        $map = WindowsProcess::parseListeners(['code' => 0, 'stdout' => $json, 'stderr' => '']);

        $this->assertSame(['c:/runners/runner-1' => 4120], $map);
    }

    public function testParseListenersAcceptsSingleObjectAndFailure(): void
    {
        $one = json_encode(['ProcessId' => 9, 'ExecutablePath' => 'D:\\r\\bin\\Runner.Listener.exe']);

        $ok = ['code' => 0, 'stdout' => $one, 'stderr' => ''];
        $failed = ['code' => 1, 'stdout' => '', 'stderr' => 'x'];
        $garbage = ['code' => 0, 'stdout' => 'not json', 'stderr' => ''];

        $this->assertSame(['d:/r' => 9], WindowsProcess::parseListeners($ok));
        $this->assertSame([], WindowsProcess::parseListeners($failed));
        $this->assertSame([], WindowsProcess::parseListeners($garbage));
    }

    public function testParseStatsMatchesPsShape(): void
    {
        $json = json_encode([
            ['IDProcess' => 0, 'PercentProcessorTime' => 90, 'WorkingSetPrivate' => 0, 'ElapsedTime' => 5],
            [
                'IDProcess' => 4120,
                'PercentProcessorTime' => 12,
                'WorkingSetPrivate' => 2048 * 1024,
                'ElapsedTime' => 300,
            ],
        ]);

        $stats = WindowsProcess::parseStats(['code' => 0, 'stdout' => $json, 'stderr' => '']);

        $this->assertSame(
            [4120 => ['cpu_percent' => 12.0, 'rss_kb' => 2048, 'uptime_seconds' => 300]],
            $stats
        );
    }

    #[RunInSeparateProcess]
    public function testLiveListenersAreKeyedBySpellingOfPoolDir(): void
    {
        $exe = str_replace('/', '\\', strtoupper($this->dir)) . '\\runner-1\\bin\\Runner.Listener.exe';
        Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => json_encode(['ProcessId' => 321, 'ExecutablePath' => $exe]),
            'stderr' => '',
        ]);

        $map = RunnerPool::liveListenersByDir();

        $this->assertSame([$this->dir . '/runner-1' => 321], $map);
    }

    #[RunInSeparateProcess]
    public function testStartSpawnsThroughPowerShellAndStoresListenerPid(): void
    {
        ProcessControl::fakeTermWait(0);
        file_put_contents($this->dir . '/runner-1/.runner', '{"agentName":"acme-1"}');
        $commands = [];
        $spawned = false;
        Shell::fake(function (array $cmd, int $t, ?string $cwd) use (&$commands, &$spawned): array {
            $commands[] = [$cmd, $cwd];
            $script = (string) end($cmd);
            if (str_contains($script, 'Start-Process')) {
                $spawned = true;
                return ['code' => 0, 'stdout' => "555\r\n", 'stderr' => ''];
            }
            if ($spawned && str_contains($script, 'Runner.Listener.exe')) {
                $exe = str_replace('/', '\\', $this->dir) . '\\runner-1\\bin\\Runner.Listener.exe';
                $row = json_encode(['ProcessId' => 556, 'ExecutablePath' => $exe]);
                return ['code' => 0, 'stdout' => $row, 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [50 * 1024 ** 3, 100 * 1024 ** 3]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 8 * 1024 * 1024);

        $info = new RunnerInfo(
            id: 'runner-1',
            dir: $this->dir . '/runner-1',
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );
        $result = ProcessControl::startIndividual($info);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('runner-1 started (pid 556)', $result['message']);
        $this->assertSame('556', file_get_contents($this->dir . '/runner-1/runner.pid'));
        $spawns = array_filter($commands, fn ($c) => str_contains((string) end($c[0]), 'Start-Process'));
        $spawn = array_values($spawns)[0];
        $this->assertSame('powershell.exe', $spawn[0][0]);
        $this->assertSame($this->dir . '/runner-1', $spawn[1]);
        $this->assertStringContainsString('run.cmd', end($spawn[0]));
        $this->assertStringNotContainsString('bash', implode(' ', $spawn[0]));
    }

    #[RunInSeparateProcess]
    public function testStopUsesTaskkillTree(): void
    {
        ProcessControl::fakeTermWait(0);
        RunnerPool::fakeLiveListeners([$this->dir . '/runner-1' => 777]);
        $killed = [];
        Shell::fake(function (array $cmd) use (&$killed): array {
            if ($cmd[0] === 'taskkill') {
                $killed[] = $cmd;
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => 'INFO: No tasks are running', 'stderr' => ''];
        });
        $info = new RunnerInfo(
            id: 'runner-1',
            dir: $this->dir . '/runner-1',
            configured: true,
            agentName: 'acme-1',
            localRunning: true,
            pid: 777,
            logTail: [],
        );

        $result = ProcessControl::stopIndividual($info);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([['taskkill', '/PID', '777', '/T', '/F']], $killed);
    }

    public function testConfigureRefusesUnsafeNameOnWindows(): void
    {
        file_put_contents($this->dir . '/runner-1/config.cmd', '@echo off');
        Shell::fake(function (array $cmd): array {
            $this->fail('config.cmd must not run for an unsafe name: ' . implode(' ', $cmd));
        });

        $result = Provisioner::ensureConfigured($this->dir . '/runner-1', 'evil&calc');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('refusing', $result['message']);
    }
}
