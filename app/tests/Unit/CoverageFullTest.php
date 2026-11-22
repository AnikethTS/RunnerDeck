<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Configuration\Config;
use RunnerDeck\Http\Halt;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\Provisioner;
use RunnerDeck\Pool\RunnerInfo;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\System\Platform;
use RunnerDeck\System\Shell;

final class CoverageFullTest extends TestCase
{
    private string $dir;

    private bool $swallowing = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-full-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        mkdir($this->dir . '/pool', 0755, true);
    }

    protected function tearDown(): void
    {
        Shell::fake(null);
        Platform::fakeWindows(null);
        Platform::fakeDarwin(null);
        Halt::fake(null);
        RunnerPool::fakeLiveListeners(null);
        RunnerPool::fakeCheckProcess(null);
        RunnerPool::fakeProcRoot(null);
        \RunnerDeck\Host\HostPressure::fakeDisk(null);
        \RunnerDeck\Host\HostPressure::fakeRam(null);
        \RunnerDeck\Host\HostPressure::fakeMeminfo(null);
        \RunnerDeck\Host\SystemStats::fakeMeminfo(null);
        \RunnerDeck\Presentation\Banner::fakeTty(null);
        \RunnerDeck\Security\SecurityHeaders::fakeSapi(null);
        \RunnerDeck\Security\HostingWarnings::fakeSapi(null);
        \RunnerDeck\GitHub\GithubClient::fakeFetch(null);
        \RunnerDeck\GitHub\GithubClient::missCache(0);
        \RunnerDeck\GitHub\GithubApp::fakeToken(null);
        ProcessControl::fakeKill(null);
        ProcessControl::fakeAlive(null);
        ProcessControl::fakeTermWait(null);
        Provisioner::fakeAlpine(null);
        Provisioner::fakeHost(null, null);
        if ($this->swallowing) {
            restore_error_handler();
        }
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_REPO');
        putenv('RUNNERDECK_HISTORY_FILE');
        putenv('RUNNERDECK_GH_BIN');
        putenv('GH_CONFIG_DIR');
        putenv('NO_COLOR');
        putenv('NUMBER_OF_PROCESSORS');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        putenv('RUNNERDECK_IP_ALLOWLIST');
        putenv('RUNNERDECK_POOL_CAP');
        putenv('RUNNERDECK_AUTO_CLEAR_WORK');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testWindowsAndDarwinHostStats(): void
    {
        Platform::fakeWindows(true);
        putenv('NUMBER_OF_PROCESSORS=4');
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => "8192 4096\n", 'stderr' => '']);
        $win = \RunnerDeck\Host\SystemStats::snapshot();
        $this->assertSame(4, $win['cpu_cores']);
        $ram = \RunnerDeck\Host\HostPressure::snapshot();
        $this->assertArrayHasKey('ram_available_kb', $ram);
    }

    #[RunInSeparateProcess]
    public function testDarwinHostStats(): void
    {
        Platform::fakeDarwin(true);
        Shell::fake(static function (array $cmd): array {
            $key = $cmd[2] ?? '';
            if ($key === 'hw.ncpu') {
                return ['code' => 0, 'stdout' => "8\n", 'stderr' => ''];
            }
            if ($key === 'hw.memsize') {
                return ['code' => 0, 'stdout' => "1048576\n", 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        $mac = \RunnerDeck\Host\SystemStats::snapshot();
        $this->assertSame(8, $mac['cpu_cores']);
        $this->assertSame(1024, $mac['mem_total_kb']);
    }

    #[RunInSeparateProcess]
    public function testDarwinStatsAndMeminfoCanFail(): void
    {
        Platform::fakeDarwin(true);
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => "\n", 'stderr' => '']);
        $mac = \RunnerDeck\Host\SystemStats::snapshot();
        $this->assertSame(1, $mac['cpu_cores']);
        $this->assertNull($mac['mem_total_kb']);
    }

    #[RunInSeparateProcess]
    public function testMeminfoWithoutAReadableTotal(): void
    {
        \RunnerDeck\Host\SystemStats::fakeMeminfo("MemTotal: nope\n");
        \RunnerDeck\Host\HostPressure::fakeMeminfo("MemTotal: 1 kB\n");
        $this->assertNull(\RunnerDeck\Host\SystemStats::snapshot()['mem_total_kb']);
        $this->assertNull(\RunnerDeck\Host\HostPressure::snapshot()['ram_available_kb']);
    }

    #[RunInSeparateProcess]
    public function testBannerColorAndSecurityHeaders(): void
    {
        putenv('NO_COLOR=1');
        $this->expectOutputRegex('/RunnerDeck/');
        \RunnerDeck\Presentation\Banner::show();
        putenv('NO_COLOR');
        \RunnerDeck\Presentation\Banner::fakeTty(true);
        \RunnerDeck\Presentation\Banner::show();
        Platform::fakeWindows(true);
        \RunnerDeck\Presentation\Banner::show();

        $this->swallowHeaders();
        \RunnerDeck\Security\SecurityHeaders::send();
        \RunnerDeck\Security\SecurityHeaders::send();
        \RunnerDeck\Security\SecurityHeaders::fakeSapi('cli-server');
        \RunnerDeck\Security\SecurityHeaders::send();
        \RunnerDeck\Security\HostingWarnings::fakeSapi('');
        $this->assertSame('(unknown)', \RunnerDeck\Security\HostingWarnings::serverLabel());
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(\RunnerDeck\Security\Session::requestIsHttps());
    }

    public function testAuthAndClientGateHaltInsteadOfExiting(): void
    {
        $this->swallowHeaders();
        Halt::fake(static function (): never {
            throw new \RuntimeException('halted');
        });
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        try {
            \RunnerDeck\Security\Auth::requirePageAccess();
            $this->fail('redirect should halt');
        } catch (\RuntimeException $e) {
            $this->assertSame('halted', $e->getMessage());
        }

        putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        putenv('RUNNERDECK_IP_ALLOWLIST=203.0.113.9');
        Halt::fake(static function (): never {
            throw new \RuntimeException('halted');
        });
        $this->expectOutputRegex('/Login required/');
        try {
            \RunnerDeck\Security\Auth::requirePageAccess();
        } catch (\RuntimeException $e) {
            $this->assertSame('halted', $e->getMessage());
        }
    }

    public function testApiAndPlainDenialsHalt(): void
    {
        $this->expectOutputRegex('/authentication required/');
        $this->swallowHeaders();
        Halt::fake(static function (): never {
            throw new \RuntimeException('halted');
        });
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        putenv('RUNNERDECK_IP_ALLOWLIST=203.0.113.9');
        try {
            \RunnerDeck\Security\Auth::rejectApiIfProtected();
        } catch (\RuntimeException $e) {
            $this->assertSame('halted', $e->getMessage());
        }
        Halt::fake(static function (): never {
            throw new \RuntimeException('halted');
        });
        try {
            \RunnerDeck\Security\Auth::rejectPlainIfProtected();
        } catch (\RuntimeException $e) {
            $this->assertSame('halted', $e->getMessage());
        }
        \RunnerDeck\Security\ClientGate::$denyHandler = null;
        foreach (['html', 'json', 'plain'] as $format) {
            Halt::fake(static function (): never {
                throw new \RuntimeException('halted');
            });
            putenv('RUNNERDECK_IP_ALLOWLIST');
            try {
                \RunnerDeck\Security\ClientGate::enforce($format);
            } catch (\RuntimeException $e) {
                $this->assertSame('halted', $e->getMessage());
            }
        }
        Halt::fake(static function (): never {
            throw new \RuntimeException('halted');
        });
        try {
            \RunnerDeck\Http\Api::respond(['ok' => true]);
        } catch (\RuntimeException $e) {
            $this->assertSame('halted', $e->getMessage());
        }
    }

    #[RunInSeparateProcess]
    public function testBootstrapAutoloadRequiresARealClass(): void
    {
        require dirname(__DIR__, 2) . '/src/bootstrap.php';
        $fns = spl_autoload_functions() ?: [];
        $loader = $fns[array_key_last($fns)];
        $this->assertIsCallable($loader);
        $this->assertFalse(class_exists(\RunnerDeck\Http\Halt::class, false));
        $loader(\RunnerDeck\Http\Halt::class);
        $this->assertTrue(class_exists(\RunnerDeck\Http\Halt::class, false));
    }

    #[RunInSeparateProcess]
    public function testRunnerListCacheLockAndAuthSuccess(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $cache = $this->dir . '/gh_runners_cache.json';
        file_put_contents($cache, json_encode([
            'fetched_at' => time(),
            'runners' => [],
        ]));
        \RunnerDeck\GitHub\GithubClient::missCache(1);
        $this->assertSame([], \RunnerDeck\GitHub\GithubClient::listRunners());

        \RunnerDeck\GitHub\GithubClient::missCache(2);
        \RunnerDeck\GitHub\GithubClient::fakeFetch(static fn (): array => []);
        @unlink($cache);
        $this->assertSame([], \RunnerDeck\GitHub\GithubClient::listRunners());
        $this->assertFileExists($cache);

        \RunnerDeck\GitHub\GithubClient::missCache(2);
        \RunnerDeck\GitHub\GithubClient::fakeFetch(static fn (): array => [
            "\xB1" => ['id' => 1, 'status' => 'online', 'busy' => false, 'labels' => [], 'os' => 'linux'],
        ]);
        @unlink($cache);
        $this->assertArrayHasKey("\xB1", \RunnerDeck\GitHub\GithubClient::listRunners());

        $lock = fopen($this->dir . '/gh_runners_cache.json.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        \RunnerDeck\GitHub\GithubClient::missCache(0);
        \RunnerDeck\GitHub\GithubClient::fakeFetch(static fn (): array => []);
        @unlink($cache);
        $this->assertSame([], \RunnerDeck\GitHub\GithubClient::listRunners());
        flock($lock, LOCK_UN);
        fclose($lock);

        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => "ok\n", 'stderr' => '']);
        \RunnerDeck\GitHub\GithubClient::fakeFetch(static fn (): array => []);
        $status = \RunnerDeck\GitHub\GithubClient::authStatus();
        $this->assertTrue($status->loggedIn && $status->orgAccessOk);

        $decoded = \RunnerDeck\GitHub\GithubClient::decodeRunnerList(
            '[{"name":"a","id":1,"status":"online","busy":false,"labels":[],"os":"linux"}]'
        );
        $this->assertArrayHasKey('a', $decoded);
    }

    #[RunInSeparateProcess]
    public function testRegistrationAndDownloadFailures(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        Shell::fake(static fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'denied']);
        try {
            \RunnerDeck\GitHub\GithubClient::registrationToken();
            $this->fail('token should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('registration token', $e->getMessage());
        }
        $calls = 0;
        Shell::fake(static function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return ['code' => 0, 'stdout' => "HTTP/2 200\n\n[]", 'stderr' => ''];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'no release'];
        });
        try {
            \RunnerDeck\GitHub\GithubClient::listRunnerDownloads();
            $this->fail('downloads should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('latest runner release', $e->getMessage());
        }
    }

    #[RunInSeparateProcess]
    public function testConfigEdgesAndGhPaths(): void
    {
        $bound = new \ReflectionMethod(Config::class, 'optionalBoundEnv');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB=3');
        $this->assertSame(7, $bound->invoke(null, 'RUNNERDECK_MIN_FREE_RAM_MIB', 5, 10, 7));
        $ips = implode(',', array_map(static fn (int $i): string => '203.0.113.' . $i, range(1, 65)));
        $this->assertNull(Config::parseIpAllowlist($ips));

        putenv('GH_CONFIG_DIR');
        putenv('RUNNERDECK_GH_BIN');
        Platform::fakeWindows(true);
        $this->assertSame('gh', Config::ghBinary());
        $this->assertNull(Config::ghConfigDir());
        $this->assertNull(Config::ghConfigDir());
    }

    #[RunInSeparateProcess]
    public function testWindowsProcessAndSlotDisk(): void
    {
        Platform::fakeWindows(true);
        Shell::fake(static function (array $cmd): array {
            $text = implode(' ', $cmd);
            if (str_contains($text, 'Win32_OperatingSystem')) {
                return ['code' => 0, 'stdout' => "8192 1024\n", 'stderr' => ''];
            }
            if (str_contains($text, 'ExpandProperty Name') || str_contains($text, 'ProcessId=')) {
                return ['code' => 0, 'stdout' => "Runner.Listener.exe\n", 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '[]', 'stderr' => ''];
        });
        $this->assertSame([], \RunnerDeck\Pool\WindowsProcess::processStats());
        $this->assertTrue(\RunnerDeck\Pool\WindowsProcess::isListener(4));
        $this->assertSame([8192, 1024], \RunnerDeck\Pool\WindowsProcess::memoryKb());

        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot . '/_work/nested', 0755, true);
        file_put_contents($slot . '/_work/nested/out.txt', 'job');
        file_put_contents($slot . '/runner.log', "log\n");
        symlink($slot . '/runner.log', $slot . '/_work/nested/link');
        $info = \RunnerDeck\Host\SlotDisk::inspect($slot);
        $this->assertGreaterThan(0, $info['kb']);
    }

    #[RunInSeparateProcess]
    public function testListenerDiscoveryOnEachPlatform(): void
    {
        $root = $this->dir . '/proc';
        $pid = (string) getmypid();
        mkdir($root . '/' . $pid, 0755, true);
        $listener = $this->dir . '/pool/runner-1/bin/Runner.Listener';
        mkdir(dirname($listener), 0755, true);
        file_put_contents($listener, 'x');
        symlink($listener, $root . '/' . $pid . '/exe');
        file_put_contents($root . '/' . $pid . '/cmdline', 'Runner.Listener');
        RunnerPool::fakeProcRoot($root);
        $found = RunnerPool::liveListenersByDir();
        $this->assertSame((int) $pid, $found[dirname($listener, 2)] ?? null);

        file_put_contents($this->dir . '/pool/runner-1/runner.pid', $pid);
        $check = RunnerPool::checkProcess($this->dir . '/pool/runner-1/runner.pid');
        $this->assertTrue($check[0]);
        file_put_contents($this->dir . '/pool/runner-1/runner.pid', '0');
        $this->assertFalse(RunnerPool::checkProcess($this->dir . '/pool/runner-1/runner.pid')[0]);

        Platform::fakeDarwin(true);
        RunnerPool::fakeProcRoot(null);
        RunnerPool::resetListenersCache();
        Shell::fake(static function (array $cmd): array {
            $bin = $cmd[0] ?? '';
            if ($bin === 'ps') {
                return ['code' => 0, 'stdout' => "9 /x/bin/Runner.Listener\n", 'stderr' => ''];
            }
            if ($bin === 'lsof') {
                return ['code' => 0, 'stdout' => "n/tmp/runner-1\n", 'stderr' => ''];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => ''];
        });
        $darwin = RunnerPool::liveListenersByDir();
        $this->assertSame(9, $darwin['/tmp/runner-1'] ?? null);

        Platform::fakeDarwin(false);
        Platform::fakeWindows(true);
        RunnerPool::resetListenersCache();
        Shell::fake(static fn (): array => [
            'code' => 0,
            'stdout' => json_encode([
                'ProcessId' => 9,
                'ExecutablePath' => 'C:\\pool\\runner-1\\bin\\Runner.Listener.exe',
            ]),
            'stderr' => '',
        ]);
        file_put_contents($this->dir . '/pool/runner-1/runner.pid', '9');
        $this->assertTrue(RunnerPool::checkProcess($this->dir . '/pool/runner-1/runner.pid')[0]);
    }

    #[RunInSeparateProcess]
    public function testProcessControlStopClearAndCap(): void
    {
        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot . '/_work', 0755, true);
        $info = $this->info($slot, false);
        RunnerPool::fakeCheckProcess(static fn (): array => [true, 4242]);
        ProcessControl::fakeAlive(static function (): bool {
            static $n = 0;
            return $n++ < 1;
        });
        ProcessControl::fakeTermWait(2);
        ProcessControl::fakeKill(static function (): void {
        });
        $stopped = ProcessControl::stopIndividual($info);
        $this->assertTrue($stopped['ok']);

        ProcessControl::fakeKill(null);
        ProcessControl::fakeAlive(null);
        RunnerPool::fakeCheckProcess(static fn (): array => [true, 999999]);
        $signaled = ProcessControl::stopIndividual($info);
        $this->assertIsArray($signaled);

        RunnerPool::fakeCheckProcess(static fn (): array => [false, null]);
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => '', 'stderr' => '']);
        Platform::fakeWindows(true);
        $gone = $this->dir . '/pool/runner-9';
        mkdir($gone, 0755, true);
        $removed = ProcessControl::removeDirectory($gone);
        $this->assertTrue($removed['ok']);

        \RunnerDeck\Host\HostMode::setMaintenance(true);
        $blocked = ProcessControl::startAll(0);
        $this->assertFalse($blocked['ok']);
        \RunnerDeck\Host\HostMode::setMaintenance(false);

        $file = $this->dir . '/blocked';
        file_put_contents($file, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $file . '/settings.json');
        \RunnerDeck\Host\HostMode::setMaintenance(true);
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.invalid/hook');
        \RunnerDeck\Host\HostPressure::checkAlert([
            'ok' => true,
            'reasons' => [],
            'disk_free_kb' => 1,
            'disk_total_kb' => 2,
            'ram_available_kb' => 3,
        ]);
    }

    #[RunInSeparateProcess]
    public function testProvisionExtractAndAlpineSkip(): void
    {
        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot . '/bin', 0755, true);
        file_put_contents($slot . '/bin/installdependencies.sh', "#!/bin/sh\n");
        Platform::fakeWindows(true);
        $skipped = Provisioner::installOsDependencies($slot);
        $this->assertStringContainsString('no OS libraries', $skipped['message']);
        Platform::fakeWindows(false);
        Provisioner::fakeAlpine(true);
        $alpine = Provisioner::installOsDependencies($slot);
        $this->assertStringContainsString('Alpine', $alpine['message']);

        $long = str_repeat('e', 200) . "\nsecond";
        $this->assertStringContainsString('…', Provisioner::extractFailureMessage($long));

        Platform::fakeWindows(true);
        $commands = new \ReflectionMethod(Provisioner::class, 'extractCommands');
        $list = $commands->invoke(null, $slot . '/runner.zip', 'actions-runner-win-x64-2.1.0.zip');
        $this->assertSame('tar', $list[0][0]);

        $fallback = new \ReflectionMethod(Provisioner::class, 'extractWithFallback');
        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        $failed = $fallback->invoke(null, $slot . '/runner.zip', $blocked . '/out', [['tar', 'x']]);
        $this->assertFalse($failed['ok']);

        $which = new \ReflectionMethod(Provisioner::class, 'commandOnPath');
        Shell::fake(static fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => '']);
        $this->assertFalse($which->invoke(null, 'missing-bin'));

        $adopt = new \ReflectionMethod(Provisioner::class, 'adoptExtract');
        $stage = $this->dir . '/stage';
        mkdir($stage, 0755, true);
        file_put_contents($stage . '/pkg', 'x');
        $this->assertFalse($adopt->invoke(null, $stage, $blocked . '/dest'));
        $this->assertFalse($adopt->invoke(null, $blocked, $slot));

        $safe = new \ReflectionMethod(Provisioner::class, 'batchSafe');
        $this->assertTrue($safe->invoke(null, 'runner-1', 'linux'));
    }

    #[RunInSeparateProcess]
    public function testLogHistoryLayoutAndSmallBranches(): void
    {
        \RunnerDeck\Logging\AppLog::error('coverage', "bad \xB1\x31");
        file_put_contents(\RunnerDeck\Logging\AppLog::path(), '');
        $this->assertSame([], \RunnerDeck\Logging\AppLog::recent(5));
        unlink(\RunnerDeck\Logging\AppLog::path());
        mkdir(\RunnerDeck\Logging\AppLog::path());
        $this->swallowHeaders();
        \RunnerDeck\Logging\AppLog::clear();

        putenv('RUNNERDECK_HISTORY_FILE=' . $this->dir . '/history.sqlite');
        \RunnerDeck\Telemetry\History::record(1.0, 1);
        @unlink($this->dir . '/history_sample_ts');
        $blocked = $this->dir . '/not-a-dir';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_HISTORY_FILE=' . $blocked . '/history.sqlite');
        \RunnerDeck\Telemetry\History::record(1.0, 1);
        \RunnerDeck\Lifecycle\CrashHistory::record('runner-1', 'a');
        $this->assertSame([], \RunnerDeck\Lifecycle\CrashHistory::exportEvents());
        $this->assertSame([], \RunnerDeck\Lifecycle\CrashHistory::countsByRunner());
        $this->assertSame([], \RunnerDeck\Lifecycle\CrashHistory::timestampsByRunner());
        $this->assertNotSame([], \RunnerDeck\Lifecycle\CrashHistory::countsByUtcDay());
        $this->assertSame(0, \RunnerDeck\Lifecycle\CrashHistory::replaceEvents([
            ['ts' => time(), 'runner_id' => 'runner-1', 'agent_name' => 'a'],
        ]));

        $one = $this->dir . '/pool/runner-1';
        $two = $this->dir . '/pool/runner-2';
        foreach ([$one, $two] as $slot) {
            mkdir($slot . '/bin', 0755, true);
            mkdir($slot . '/externals/node', 0755, true);
            file_put_contents($slot . '/bin/Runner.Listener', 'listener');
            file_put_contents($slot . '/externals/node/file', 'lib');
        }
        \RunnerDeck\Pool\PoolLayout::shareExternals($one);
        \RunnerDeck\Pool\PoolLayout::shareExternals($this->dir . '/pool/missing');

        $time = new \ReflectionMethod(\RunnerDeck\Presentation\DashboardView::class, 'formatErrorTime');
        $this->assertSame('not-a-time', $time->invoke(null, 'not-a-time'));
        $this->assertSame('', \RunnerDeck\Presentation\DashboardView::orphansHtml([['name' => '']]));
    }

    /** @return array{ok: bool, message: string} */
    private function info(string $slot, bool $configured): RunnerInfo
    {
        return new RunnerInfo(
            id: 'runner-1',
            dir: $slot,
            configured: $configured,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );
    }

    private function swallowHeaders(): void
    {
        $this->swallowing = true;
        set_error_handler(static function (int $severity, string $message): bool {
            return str_contains($message, 'header')
                || str_contains($message, 'Cannot')
                || str_contains($message, 'http')
                || str_contains($message, 'directory');
        });
    }
}
