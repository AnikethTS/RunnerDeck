<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Backup\HostBundleSnapshot;
use RunnerDeck\GitHub\AgentTarget;
use RunnerDeck\GitHub\AgentVersion;
use RunnerDeck\GitHub\CompletedRunCache;
use RunnerDeck\GitHub\GithubApp;
use RunnerDeck\GitHub\GithubBackoff;
use RunnerDeck\GitHub\GithubClient;
use RunnerDeck\GitHub\GithubClock;
use RunnerDeck\GitHub\GithubQuota;
use RunnerDeck\GitHub\QueuedJobs;
use RunnerDeck\Host\SlotDisk;
use RunnerDeck\Http\Halt;
use RunnerDeck\Lifecycle\AutoRestart;
use RunnerDeck\Lifecycle\CrashState;
use RunnerDeck\Lifecycle\Drain;
use RunnerDeck\Lifecycle\IdleState;
use RunnerDeck\Lifecycle\RollingUpgrade;
use RunnerDeck\Pool\PoolLayout;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\Provisioner;
use RunnerDeck\Pool\RunnerInfo;
use RunnerDeck\Pool\RunnerLog;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\Pool\SlotFlags;
use RunnerDeck\Security\Auth;
use RunnerDeck\Security\ClientGate;
use RunnerDeck\System\Platform;
use RunnerDeck\System\Shell;
use RunnerDeck\Telemetry\WatchHeartbeat;

final class CoverageLastTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-last-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        mkdir($this->dir . '/pool', 0755, true);
        foreach (
            [
                'RUNNERDECK_AUTO_RESTART',
                'RUNNERDECK_SCALE_TO_QUEUE',
                'RUNNERDECK_IDLE_STOP_MINUTES',
                'RUNNERDECK_QUEUE_WAIT_MINUTES',
                'RUNNERDECK_LONG_JOB_MINUTES',
                'RUNNERDECK_CRASH_WEBHOOK_URL',
                'RUNNERDECK_BUNDLE_SNAPSHOT',
                'RUNNERDECK_LOG_RETENTION_DAYS',
                'RUNNERDECK_POOL_CAP',
                'RUNNERDECK_REQUIRE_MTLS',
                'RUNNERDECK_IP_ALLOWLIST',
                'RUNNERDECK_AUTH_TOTP_SECRET',
                'RUNNERDECK_GITHUB_APP_ID',
                'RUNNERDECK_GITHUB_APP_INSTALLATION_ID',
                'RUNNERDECK_GITHUB_APP_KEY_FILE',
            ] as $name
        ) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        Shell::fake(null);
        Platform::fakeWindows(null);
        Platform::fakeDarwin(null);
        RunnerPool::fakeCheckProcess(null);
        RunnerPool::fakeLiveListeners(null);
        RunnerPool::fakeProcRoot(null);
        ProcessControl::fakeAlive(null);
        ProcessControl::fakeKill(null);
        ProcessControl::fakeTermWait(null);
        Provisioner::fakeHost(null, null);
        Provisioner::fakeAlpine(null);
        GithubClient::fakeFetch(null);
        GithubApp::fakeToken(null);
        AgentTarget::fake(null);
        HostBundleSnapshot::fakeNow(null);
        Drain::fakeSleep(null);
        Drain::fakeNow(null);
        IdleState::fakeNow(null);
        WatchHeartbeat::fakeNow(null);
        ClientGate::$denyHandler = null;
        Halt::fake(null);
        SlotDisk::resetCache();
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_REPO');
        foreach ($this->saved as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testQueueBranchesAndCachePayload(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $runs = [['id' => 1, 'status' => 'completed', 'updated_at' => '']];
        $jobs = [[
            'id' => 99,
            'status' => 'completed',
            'runner_name' => 'acme-1',
            'name' => 'done',
            'started_at' => gmdate('c'),
        ]];
        for ($i = 2; $i <= 8; $i++) {
            $runs[] = ['id' => $i, 'status' => 'in_progress', 'updated_at' => ''];
        }
        for ($i = 1; $i <= 31; $i++) {
            $jobs[] = [
                'id' => $i,
                'status' => 'in_progress',
                'runner_name' => 'acme-1',
                'name' => 'build',
                'started_at' => gmdate('c'),
                'html_url' => '',
            ];
        }
        $runsJson = json_encode(['workflow_runs' => $runs]);
        $jobsJson = json_encode(['jobs' => $jobs]);
        Shell::fake(static function (array $cmd) use ($runsJson, $jobsJson): array {
            $text = implode(' ', $cmd);
            if (str_contains($text, 'auth')) {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (str_contains($text, 'orgs/') && str_contains($text, 'in_progress')) {
                return ['code' => 0, 'stdout' => '{"workflow_runs":"nope"}', 'stderr' => ''];
            }
            if (str_contains($text, '/jobs') && str_contains($text, '/runs/1/')) {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'jobs'];
            }
            if (str_contains($text, '/jobs')) {
                return ['code' => 0, 'stdout' => (string) $jobsJson, 'stderr' => ''];
            }
            if (str_contains($text, 'actions/runs')) {
                return ['code' => 0, 'stdout' => (string) $runsJson, 'stderr' => ''];
            }
            if (str_contains($text, '/repos')) {
                return ['code' => 0, 'stdout' => '[{"full_name":"acme/widgets"}]', 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        $snap = QueuedJobs::snapshot();
        $this->assertTrue($snap['ok']);
        $this->assertTrue($snap['truncated']);
        file_put_contents($this->dir . '/queue_cache.json', '"nope"');
        $this->assertSame(0, QueuedJobs::peekCachedCount());
    }

    public function testSmallStateFilesAndAuthGates(): void
    {
        file_put_contents($this->dir . '/watch_heartbeat.json', '{"ticked_at":0}');
        $this->assertFalse(WatchHeartbeat::isFresh());

        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot . '/bin', 0755, true);
        file_put_contents($slot . '/bin/Runner.Listener.deps.json', '{}');
        file_put_contents($slot . '/.runner', '"nope"');
        $this->assertNull(AgentVersion::read($slot));

        file_put_contents($this->dir . '/agent_target.json', json_encode([
            'version' => '2.9.0',
            'fetched_at' => time(),
        ]));
        AgentTarget::fake(null);
        $this->assertSame('2.9.0', AgentTarget::latest());

        file_put_contents($this->dir . '/gh_clock.json', '"nope"');
        $this->assertNull(GithubClock::warning());
        GithubClock::observeHttpDate('   ');
        $this->assertSame([], IdleState::dueIds(0));
        file_put_contents($this->dir . '/idle_state.json', '"nope"');
        $this->assertSame([], IdleState::dueIds(1));

        file_put_contents($this->dir . '/github_app_token.json', '"nope"');
        putenv('RUNNERDECK_GITHUB_APP_ID=11');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID=22');
        $this->assertNull(GithubApp::token());

        $empty = $this->dir . '/empty.log';
        file_put_contents($empty, '');
        $this->assertSame([], RunnerLog::tailFile($empty, 5));

        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '';
        $this->assertTrue(ClientGate::extraGateOk());
        Auth::rejectPlainIfProtected();

        $_SERVER['HTTP_HOST'] = 'runnerdeck.example';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        ClientGate::$denyHandler = static function (string $format): void {
            $GLOBALS['rd_gate_format'] = $format;
        };
        ClientGate::enforce('json');
        $this->assertSame('json', $GLOBALS['rd_gate_format']);
        $_SERVER['REMOTE_ADDR'] = '';
        putenv('RUNNERDECK_IP_ALLOWLIST=203.0.113.0/24');
        $this->assertFalse(ClientGate::allowlistMatch());
        putenv('RUNNERDECK_IP_ALLOWLIST');
        putenv('RUNNERDECK_REQUIRE_MTLS=1');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['SSL_CLIENT_VERIFY'] = 'SUCCESS';
        $this->assertSame('mTLS', ClientGate::extraGateFact());
        $this->assertFalse(ClientGate::isValidCidrOrIp(''));
        $this->assertFalse(ClientGate::isValidCidrOrIp('10.0.0.0/x'));
        $this->assertTrue(ClientGate::isValidCidrOrIp('10.0.0.0/8'));

        $this->swallow();
        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        $this->assertFalse(Auth::attempt('000000'));
        GithubClock::observeHttpDate('Sun, 22 Nov 2026 12:00:00 GMT');
        GithubBackoff::noteFailure('HTTP 429');
        GithubQuota::observe(10, 5000, time() + 60);
        CrashState::markExplicitlyStopped('runner-1');
        CrashState::track([['id' => 'runner-1', 'local_running' => false, 'configured' => true]]);
        IdleState::sync([[
            'id' => 'runner-1',
            'local_running' => true,
            'github' => ['busy' => false],
        ]]);
        AgentTarget::fake(null);
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        Shell::fake(fn (array $cmd): array => $this->downloadShell($cmd, ''));
        $this->assertSame('2.329.0', AgentTarget::latest());
        restore_error_handler();

        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        mkdir($this->dir . '/queue_completed_runs.json.' . getmypid() . '.tmp', 0755, true);
        CompletedRunCache::put('k', [['id' => 1]]);
        $lock = $this->dir . '/crash_state.json.lock';
        mkdir($lock, 0755, true);
        CrashState::setAutoRestartPaused('runner-1', true);
    }

    public function testGithubAppWriteAndSnapshots(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        file_put_contents($this->dir . '/app.pem', $pem);
        putenv('RUNNERDECK_GITHUB_APP_ID=11');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID=22');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE=' . $this->dir . '/app.pem');
        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        Shell::fake(static fn (): array => [
            'code' => 0,
            'stdout' => '{"token":"ghs_abc","expires_at":"2099-01-01T00:00:00Z"}',
            'stderr' => '',
        ]);
        $this->assertSame('ghs_abc', GithubApp::token());

        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        HostBundleSnapshot::fakeNow(static fn (): int => 1578000000);
        $this->assertNull(HostBundleSnapshot::pathFor('nope'));
        $this->assertNull(HostBundleSnapshot::pathFor('2020-01-02'));
        $bundleDir = $this->dir . '/bundles';
        mkdir($bundleDir, 0700, true);
        file_put_contents($this->dir . '/outside.json', '{}');
        symlink($this->dir . '/outside.json', $bundleDir . '/host-2020-01-03.json');
        $this->assertNull(HostBundleSnapshot::pathFor('2020-01-03'));
        $this->assertFalse(HostBundleSnapshot::delete('2020-01-02'));
        mkdir($bundleDir . '/host-2020-01-02.json', 0755, true);
        HostBundleSnapshot::tick();
        mkdir($bundleDir . '/host-2020-01-02.json.' . getmypid() . '.tmp', 0755, true);
        HostBundleSnapshot::tick();
    }

    public function testPoolLayoutAndDisk(): void
    {
        foreach (['runner-base', 'runner-1', 'runner-2', 'runner-3'] as $id) {
            mkdir($this->dir . '/pool/' . $id, 0755, true);
        }
        $this->assertArrayHasKey('runner-base', RunnerPool::discover(0));
        $order = new \ReflectionMethod(RunnerPool::class, 'compareIds');
        $this->assertSame(-1, $order->invoke(null, 'runner-base', 'runner-2'));

        $this->isolatedShare('same', static function (string $pool): void {
            unlink($pool . '/runner-2/externals/keep.txt');
            link($pool . '/runner-1/externals/keep.txt', $pool . '/runner-2/externals/keep.txt');
        });
        $empty = $this->dir . '/pools/emptytrees';
        mkdir($empty . '/runner-1/externals/only', 0755, true);
        mkdir($empty . '/runner-2/externals/only', 0755, true);
        putenv('RUNNERDECK_POOL_DIR=' . $empty);
        $this->assertFalse(PoolLayout::externalsDuplicated());
        $this->isolatedShare('nolistener', static function (string $pool): void {
            unlink($pool . '/runner-2/bin/Runner.Listener');
        });
        $this->isolatedShare('nested', static function (string $pool): void {
            mkdir($pool . '/runner-1/externals/nested', 0755, true);
            file_put_contents($pool . '/runner-1/externals/nested/file.txt', 'x');
            file_put_contents($pool . '/runner-2/externals/nested', 'not-a-dir');
        });
        $this->isolatedShare('rdlink', static function (string $pool): void {
            file_put_contents($pool . '/runner-1/externals/fresh.txt', 'fresh');
            file_put_contents($pool . '/runner-2/externals/fresh.txt.rdlink', 'old');
        });
        $this->isolatedShare('linkfail', static function (string $pool): void {
            file_put_contents($pool . '/runner-1/externals/blocked.txt', 'x');
            mkdir($pool . '/runner-2/externals/blocked.txt.rdlink', 0755, true);
        });
        $this->isolatedShare('renamefail', static function (string $pool): void {
            file_put_contents($pool . '/runner-1/externals/onto.txt', 'x');
            mkdir($pool . '/runner-2/externals/onto.txt', 0755, true);
            file_put_contents($pool . '/runner-2/externals/onto.txt/inside', 'y');
        });
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');

        $work = $this->dir . '/pool/runner-1';
        mkdir($work . '/_work', 0755, true);
        file_put_contents($work . '/runner.log', 'log');
        Shell::fake(static fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'du']);
        SlotDisk::resetCache();
        SlotDisk::inspect($work);
        Shell::fake(null);
        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        SlotDisk::resetCache();
        SlotDisk::inspect($this->dir . '/pool/runner-2');
        file_put_contents($this->dir . '/plain', 'x');
        SlotDisk::removeTree($this->dir . '/plain');

        Platform::fakeWindows(true);
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => "nope\n", 'stderr' => '']);
        $this->assertNull(\RunnerDeck\Pool\WindowsProcess::memoryKb());
    }

    public function testLifecycleEdges(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        Drain::audit([], false);
        GithubClient::fakeFetch(static fn (): array => [
            'acme-1' => [
                'id' => 1,
                'status' => 'online',
                'busy' => true,
                'labels' => [],
                'os' => 'Linux',
            ],
        ]);
        Drain::fakeSleep(null);
        $busy = Drain::waitUntilIdle('acme-1', 1, 1);
        $this->assertFalse($busy['ok']);

        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot . '/bin', 0755, true);
        file_put_contents($slot . '/bin/Runner.Listener', "echo ok\n");
        file_put_contents($slot . '/config.sh', "#!/bin/sh\n");
        file_put_contents($slot . '/.runnerdeck-ephemeral', "1\n");
        RunnerPool::fakeCheckProcess(static fn (): array => [false, null]);
        RunnerPool::fakeLiveListeners([]);
        AutoRestart::tick();

        RunnerPool::fakeCheckProcess(static fn (): array => [true, 9]);
        putenv('RUNNERDECK_POOL_CAP=1');
        $this->assertSame([], AutoRestart::scaleToQueue([
            ['id' => 'runner-1', 'configured' => true, 'local_running' => false],
        ], 3));
        putenv('RUNNERDECK_POOL_CAP');
        $this->assertSame([], AutoRestart::scaleToQueue([
            ['id' => 'missing', 'configured' => true, 'local_running' => false],
        ], 1));
        $this->assertSame([], AutoRestart::idleStop([]));

        @unlink($slot . '/.runnerdeck-ephemeral');
        $info = new RunnerInfo(
            id: 'runner-1',
            dir: $slot,
            configured: true,
            agentName: 'acme-1',
            localRunning: true,
            pid: 9,
            logTail: [],
        );
        $this->pack($this->dir . '/upgrade.tar.gz', [
            'bin/Runner.Listener' => "echo ok\n",
            'config.sh' => "#!/bin/sh\n",
            'run.sh' => "#!/bin/sh\n",
        ]);
        Shell::fake(fn (array $cmd): array => $this->downloadShell($cmd, $this->dir . '/upgrade.tar.gz'));
        RunnerPool::fakeCheckProcess(static fn (): array => [true, 9]);
        ProcessControl::fakeAlive(static fn (): bool => true);
        ProcessControl::fakeKill(static function (): void {
        });
        ProcessControl::fakeTermWait(0);
        $stopped = RollingUpgrade::run([$info], true, 0);
        $this->assertFalse($stopped['ok']);

        RunnerPool::fakeCheckProcess(static fn (): array => [false, null]);
        ProcessControl::fakeAlive(null);
        $started = RollingUpgrade::run([$info], true, 0);
        $this->assertFalse($started['ok']);
        $this->assertStringContainsString('failed to start', (string) ($started['message'] ?? ''));
    }

    public function testProvisionerEdges(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $root = $this->dir . '/pkgroot';
        mkdir($root, 0755, true);
        file_put_contents($root . '/package.json', '{}');
        $blocked = Provisioner::ensureInstalled($root . '/runner-1');
        $this->assertFalse($blocked['ok']);

        $parent = $this->dir . '/notdir';
        file_put_contents($parent, 'x');
        Shell::fake(fn (array $cmd): array => $this->downloadShell($cmd, ''));
        $this->swallow();
        $mkdir = Provisioner::ensureInstalled($parent . '/slot', true);
        restore_error_handler();
        $this->assertFalse($mkdir['ok']);

        $bare = $this->dir . '/pool/bare';
        mkdir($bare, 0755, true);
        $this->pack($this->dir . '/readme.tar.gz', ['README' => "hi\n"]);
        Shell::fake(fn (array $cmd): array => $this->downloadShell($cmd, $this->dir . '/readme.tar.gz'));
        $empty = Provisioner::ensureInstalled($bare, true);
        $this->assertFalse($empty['ok']);

        $deps = $this->dir . '/pool/deps';
        mkdir($deps, 0755, true);
        $this->pack($this->dir . '/deps.tar.gz', [
            'bin/Runner.Listener' => "echo ok\n",
            'bin/installdependencies.sh' => "#!/bin/sh\n",
            'config.sh' => "#!/bin/sh\n",
            'run.sh' => "#!/bin/sh\n",
        ]);
        Provisioner::fakeHost(false, false);
        Provisioner::fakeAlpine(false);
        Shell::fake(fn (array $cmd): array => $this->downloadShell($cmd, $this->dir . '/deps.tar.gz'));
        $libs = Provisioner::ensureInstalled($deps, true);
        $this->assertFalse($libs['ok']);

        $ready = $this->dir . '/pool/ready';
        mkdir($ready . '/bin', 0755, true);
        file_put_contents($ready . '/bin/Runner.Listener', "echo ok\n");
        file_put_contents($ready . '/config.sh', "#!/bin/sh\n");
        file_put_contents($ready . '/.runner', "1\n");
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $again = Provisioner::ensureConfigured($ready, 'acme-1', true);
        $this->assertTrue($again['ok']);

        unlink($ready . '/.runner');
        Shell::fake(static fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'no token']);
        $token = Provisioner::ensureConfigured($ready, 'acme-1', false);
        $this->assertFalse($token['ok']);

        Platform::fakeWindows(true);
        file_put_contents($ready . '/config.cmd', "echo ok\n");
        file_put_contents($ready . '/bin/Runner.Listener.exe', "echo ok\n");
        $unsafe = Provisioner::ensureConfigured($ready, 'bad&name', false);
        $this->assertFalse($unsafe['ok']);
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => "a&b\n", 'stderr' => '']);
        $unsafeToken = Provisioner::ensureConfigured($ready, 'acme-1', false);
        $this->assertFalse($unsafeToken['ok']);
        Platform::fakeWindows(false);

        Shell::fake(static function (array $cmd): array {
            $text = implode(' ', $cmd);
            if (str_contains($text, 'registration-token')) {
                return ['code' => 0, 'stdout' => "TOKEN\n", 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        $ephemeral = Provisioner::ensureConfigured($ready, 'acme-1', true);
        $this->assertTrue($ephemeral['ok']);

        $method = new \ReflectionMethod(Provisioner::class, 'adoptExtract');
        $file = $this->dir . '/a-file';
        file_put_contents($file, 'x');
        $this->assertFalse($method->invoke(null, $file, $ready));
        $stage = $this->dir . '/stage';
        mkdir($stage . '/sub', 0755, true);
        file_put_contents($stage . '/sub/a', 'a');
        mkdir($ready . '/sub', 0755, true);
        file_put_contents($ready . '/sub/b', 'b');
        $this->assertTrue($method->invoke(null, $stage, $ready));
        $phar = new \ReflectionMethod(Provisioner::class, 'pharExtract');
        $this->assertFalse($phar->invoke(null, $file, $ready));
    }

    private function isolatedShare(string $name, callable $setup): void
    {
        $pool = $this->dir . '/pools/' . $name;
        putenv('RUNNERDECK_POOL_DIR=' . $pool);
        foreach (['runner-1', 'runner-2'] as $id) {
            mkdir($pool . '/' . $id . '/bin', 0755, true);
            mkdir($pool . '/' . $id . '/externals', 0755, true);
            file_put_contents($pool . '/' . $id . '/bin/Runner.Listener', 'listener');
            file_put_contents($pool . '/' . $id . '/externals/keep.txt', 'keep');
        }
        $setup($pool);
        PoolLayout::shareExternals($pool . '/runner-2');
    }

    /** @param array<string, string> $files */
    private function pack(string $dest, array $files): void
    {
        $stage = $this->dir . '/pack-' . uniqid();
        foreach ($files as $name => $body) {
            $path = $stage . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $body);
        }
        $proc = proc_open(
            ['tar', 'czf', $dest, '-C', $stage, '.'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
    }

    /** @param list<string> $cmd */
    private function downloadShell(array $cmd, string $archive): array
    {
        $text = implode(' ', $cmd);
        if (str_contains($text, 'downloads')) {
            return ['code' => 0, 'stdout' => json_encode([$this->downloadRow()]), 'stderr' => ''];
        }
        $out = array_search('-o', $cmd, true);
        if ($out !== false && $archive !== '' && isset($cmd[$out + 1])) {
            copy($archive, $cmd[$out + 1]);
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if (in_array('tar', $cmd, true) || in_array('bsdtar', $cmd, true)) {
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($proc)) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                return ['code' => proc_close($proc), 'stdout' => '', 'stderr' => ''];
            }
        }
        if (str_contains($text, 'Runner.Listener')) {
            return ['code' => 1, 'stdout' => '', 'stderr' => 'listener'];
        }
        return ['code' => 0, 'stdout' => '', 'stderr' => ''];
    }

    /** @return array{os: string, architecture: string, filename: string, download_url: string} */
    private function downloadRow(): array
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'osx',
            'Windows' => 'win',
            default => 'linux',
        };
        $arch = match (strtolower(php_uname('m'))) {
            'aarch64', 'arm64' => 'arm64',
            'armv7l' => 'arm',
            default => 'x64',
        };
        return [
            'os' => $os,
            'architecture' => $arch,
            'filename' => "actions-runner-{$os}-{$arch}-2.329.0.tar.gz",
            'download_url' => 'https://example.invalid/runner.tar.gz',
        ];
    }

    private function swallow(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            return str_contains($message, 'header')
                || str_contains($message, 'Cannot')
                || str_contains($message, 'session')
                || str_contains($message, 'mkdir');
        });
    }
}
