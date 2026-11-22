<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\RunnerInfo;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\System\Platform;
use RunnerDeck\System\Shell;

final class CoverageRestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-rest-' . uniqid();
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
        RunnerPool::fakeCheckProcess(null);
        RunnerPool::fakeLiveListeners(null);
        ProcessControl::fakeAlive(null);
        ProcessControl::fakeKill(null);
        ProcessControl::fakeTermWait(null);
        \RunnerDeck\Alerting\SyslogSink::fakeHost(null);
        \RunnerDeck\Alerting\SyslogSink::fakeSend(null);
        \RunnerDeck\GitHub\GithubApp::fakeToken(null);
        \RunnerDeck\GitHub\GithubClient::fakeFetch(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_REPO');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        putenv('RUNNERDECK_DISK_WEBHOOK_THRESHOLD');
        putenv('RUNNERDECK_SYSLOG');
        putenv('RUNNERDECK_GITHUB_APP_ID');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE');
        putenv('RUNNERDECK_TRUST_PROXY');
        putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        putenv('RUNNERDECK_LONG_JOB_MINUTES');
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES');
        putenv('RUNNERDECK_IP_ALLOWLIST');
        putenv('RUNNERDECK_POOL_CAP');
        putenv('RUNNERDECK_AUTO_CLEAR_WORK');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testQueueScanCoversTruncationAndCacheEdges(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $jobs = [];
        for ($i = 1; $i <= 20; $i++) {
            $jobs[] = [
                'id' => $i,
                'status' => 'queued',
                'name' => 'build',
                'labels' => ['self-hosted-runnerdeck'],
                'created_at' => gmdate('c'),
            ];
        }
        $completed = [[
            'id' => 50,
            'status' => 'completed',
            'name' => 'done',
            'labels' => ['self-hosted-runnerdeck'],
            'runner_name' => 'acme-1',
            'conclusion' => 'success',
            'completed_at' => gmdate('c'),
        ]];
        Shell::fake(static function (array $cmd) use ($jobs, $completed): array {
            $text = implode(' ', $cmd);
            if (str_contains($text, 'auth')) {
                return ['code' => 0, 'stdout' => "ok\n", 'stderr' => ''];
            }
            $body = match (true) {
                str_contains($text, '/repos?') => [['full_name' => 'acme/widgets']],
                str_contains($text, 'status=queued') => ['workflow_runs' => [[
                    'id' => 7,
                    'status' => 'queued',
                    'updated_at' => '2026-01-01T00:00:00Z',
                ]]],
                str_contains($text, 'status=in_progress') && str_contains($text, 'orgs/') => [
                    'workflow_runs' => [[
                        'id' => 9,
                        'status' => 'in_progress',
                        'html_url' => 'https://example.invalid/run/9',
                        'repository' => ['full_name' => 'acme/widgets'],
                    ]],
                ],
                str_contains($text, 'status=completed') => ['workflow_runs' => [[
                    'id' => 8,
                    'status' => 'completed',
                    'updated_at' => '2026-01-02T00:00:00Z',
                ]]],
                str_contains($text, '/runs/8/jobs') => ['jobs' => $completed],
                str_contains($text, '/runs/9/jobs') => ['jobs' => [[
                    'id' => 90,
                    'status' => 'in_progress',
                    'name' => 'live',
                    'runner_name' => 'acme-1',
                    'html_url' => '',
                ]]],
                str_contains($text, '/jobs') => ['jobs' => $jobs],
                default => [],
            };
            return ['code' => 0, 'stdout' => "HTTP/2 200\n\n" . json_encode($body), 'stderr' => ''];
        });
        $snap = \RunnerDeck\GitHub\QueuedJobs::snapshot();
        $this->assertTrue($snap['ok']);
        $this->assertGreaterThan(0, $snap['count']);

        file_put_contents($this->dir . '/queue_cache.json', json_encode([
            'fetched_at' => time(),
            'payload' => ['count' => 3, 'recent' => 'nope'],
        ]));
        $this->assertSame([], \RunnerDeck\GitHub\QueuedJobs::peekRecent());
        file_put_contents($this->dir . '/queue_cache.json', json_encode([
            'fetched_at' => time(),
            'payload' => 'nope',
        ]));
        $this->assertSame(0, \RunnerDeck\GitHub\QueuedJobs::peekCachedCount());
        file_put_contents($this->dir . '/queue_cache.json', json_encode([
            'fetched_at' => 1,
            'payload' => ['count' => 1, 'recent' => []],
        ]));
        $read = new \ReflectionMethod(\RunnerDeck\GitHub\QueuedJobs::class, 'readCache');
        $this->assertNull($read->invoke(null));
        $try = new \ReflectionMethod(\RunnerDeck\GitHub\QueuedJobs::class, 'tryGetJson');
        $deadline = new \ReflectionProperty(\RunnerDeck\GitHub\QueuedJobs::class, 'deadline');
        $deadline->setValue(null, 1.0);
        $this->assertNull($try->invoke(null, 'repos/acme/widgets'));

        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        $write = new \ReflectionMethod(\RunnerDeck\GitHub\QueuedJobs::class, 'writeCache');
        $write->invoke(null, ['count' => 0]);
    }

    #[RunInSeparateProcess]
    public function testRollingUpgradeStartsAfterARealExtract(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot, 0755, true);
        $download = $this->download();
        $checks = 0;
        RunnerPool::fakeCheckProcess(static function () use (&$checks): array {
            $checks++;
            return $checks < 2 ? [false, null] : [true, 42];
        });
        RunnerPool::fakeLiveListeners([]);
        Shell::fake(function (array $cmd) use ($download): array {
            $bin = $cmd[0] ?? '';
            if ($bin === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                $this->writePackage($out);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if ($bin === 'tar' || $bin === 'bsdtar') {
                $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($proc)) {
                    return ['code' => 1, 'stdout' => '', 'stderr' => 'tar missing'];
                }
                $stdout = stream_get_contents($pipes[1]) ?: '';
                $stderr = stream_get_contents($pipes[2]) ?: '';
                fclose($pipes[1]);
                fclose($pipes[2]);
                return ['code' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
            }
            if (str_contains(implode(' ', $cmd), 'downloads')) {
                return [
                    'code' => 0,
                    'stdout' => "HTTP/2 200\n\n" . json_encode([$download]),
                    'stderr' => '',
                ];
            }
            return ['code' => 0, 'stdout' => "42\n", 'stderr' => ''];
        });
        $runner = new RunnerInfo(
            id: 'runner-1',
            dir: $slot,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
            agentVersion: '2.1.0',
        );
        $result = \RunnerDeck\Lifecycle\RollingUpgrade::run([$runner], true, 0);
        $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));
        $this->assertStringContainsString('upgraded', (string) $result['message']);
    }

    #[RunInSeparateProcess]
    public function testProcessControlRenameCapAndFailedStop(): void
    {
        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot, 0755, true);
        $info = new RunnerInfo(
            id: 'runner-1',
            dir: $slot,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        \RunnerDeck\GitHub\GithubClient::fakeFetch(static fn (): array => []);
        $n = 0;
        RunnerPool::fakeCheckProcess(static function () use (&$n): array {
            $n++;
            return $n === 1 ? [false, null] : [true, 42];
        });
        RunnerPool::fakeLiveListeners([]);
        $renamed = ProcessControl::renameRunner($info, 'acme-2');
        $this->assertTrue($renamed['ok']);

        RunnerPool::fakeCheckProcess(static fn (): array => [true, 4242]);
        ProcessControl::fakeAlive(static fn (): bool => true);
        ProcessControl::fakeKill(static function (): void {
        });
        ProcessControl::fakeTermWait(0);
        $deleted = ProcessControl::deleteRunner($info);
        $this->assertFalse($deleted['ok']);
        $restarted = ProcessControl::restartIndividual($info);
        $this->assertFalse($restarted['ok']);

        putenv('RUNNERDECK_POOL_CAP=1');
        $added = ProcessControl::addRunner('extra');
        $this->assertFalse($added['ok']);

        $trash = $slot . '.deleting';
        mkdir($trash, 0755, true);
        Platform::fakeWindows(true);
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => '', 'stderr' => '']);
        $this->assertTrue(ProcessControl::removeDirectory($slot)['ok']);
    }

    #[RunInSeparateProcess]
    public function testSmallAlertAndAuthEdges(): void
    {
        \RunnerDeck\Alerting\CrashWebhook::notifyDisk('disk_slot', 'low', null, null, 1, 1);
        $post = new \ReflectionMethod(\RunnerDeck\Alerting\CrashWebhook::class, 'post');
        $encoded = $post->invoke(null, 'https://example.invalid/h', ['m' => "\xB1"]);
        $this->assertFalse($encoded[0]);

        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.invalid/h');
        putenv('RUNNERDECK_DISK_WEBHOOK_THRESHOLD=1');
        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        \RunnerDeck\Alerting\DiskAlert::check([['id' => 'runner-1', 'disk_kb' => 1]], null);

        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        \RunnerDeck\Alerting\WebhookOutbox::enqueue('https://example.invalid/a', [], 'crash', "\xB1", null);
        \RunnerDeck\Alerting\SyslogSink::fakeHost('');
        \RunnerDeck\Alerting\SyslogSink::fakeSend(static fn (): bool => true);
        putenv('RUNNERDECK_SYSLOG=1');
        \RunnerDeck\Alerting\SyslogSink::emit('crash.loop', 'hello');
        $this->assertStringContainsString('localhost', \RunnerDeck\Alerting\SyslogSink::format('crash.loop', 'hello'));

        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        putenv('RUNNERDECK_TRUST_PROXY=1');
        $this->assertTrue(\RunnerDeck\Security\Session::requestIsHttps());

        $this->assertStringContainsString(
            'gh auth login',
            (string) \RunnerDeck\GitHub\GithubAuth::staleWarning(false, false, 'HTTP 401')
        );
        putenv('RUNNERDECK_GITHUB_APP_ID=1');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID=2');
        file_put_contents($this->dir . '/app.pem', "not-a-key\n");
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE=' . $this->dir . '/app.pem');
        $this->assertStringContainsString(
            'GitHub App',
            (string) \RunnerDeck\GitHub\GithubAuth::staleWarning(true, false, 'HTTP 401')
        );

        Shell::fake(static fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => '']);
        $this->assertFalse(Platform::commandExists('missing'));

        file_put_contents($this->dir . '/settings.json', '"nope"');
        $this->assertSame([], \RunnerDeck\Configuration\Settings::load());

        $bundle = \RunnerDeck\Backup\HostBundle::import([
            'kind' => \RunnerDeck\Backup\HostBundle::KIND,
            'version' => \RunnerDeck\Backup\HostBundle::VERSION,
            'settings' => [],
            'secrets' => ['RUNNERDECK_CRASH_WEBHOOK_URL' => 'https://example.invalid/hook'],
        ]);
        $this->assertTrue($bundle['ok']);
    }

    public function testLeftoverBranches(): void
    {
        $_SERVER['HTTPS'] = '';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = '';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $this->assertTrue(\RunnerDeck\Security\Session::requestIsHttps());

        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.invalid/h');
        putenv('RUNNERDECK_LONG_JOB_MINUTES=1');
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES=1');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        \RunnerDeck\Alerting\LongJobAlert::check([]);
        \RunnerDeck\Alerting\QueueAlert::check([]);
        \RunnerDeck\GitHub\AgentRoster::replace(['acme-1']);
        \RunnerDeck\GitHub\CompletedRunCache::put('k', []);
        \RunnerDeck\Telemetry\WatchHeartbeat::touch();
        file_put_contents($this->dir . '/settings.json', json_encode(['RUNNERDECK_ORG' => 'acme']));
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        $imported = \RunnerDeck\Configuration\SettingsPortable::import(['RUNNERDECK_ORG' => '']);
        $this->assertFalse(\RunnerDeck\Configuration\SettingsPortable::import(['nope' => 'x'])['ok']);
        $this->assertTrue($imported['ok']);

        mkdir($this->dir . '/pool/runner-base', 0755, true);
        mkdir($this->dir . '/pool/runner-2', 0755, true);
        $this->assertArrayHasKey('runner-base', RunnerPool::discover(0));
        Platform::fakeDarwin(true);
        RunnerPool::resetListenersCache();
        Shell::fake(static fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'ps']);
        $this->assertSame([], RunnerPool::liveListenersByDir());
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => "\n", 'stderr' => '']);
        file_put_contents($this->dir . '/pool/runner-2/runner.pid', (string) getmypid());
        $this->assertIsArray(RunnerPool::checkProcess($this->dir . '/pool/runner-2/runner.pid'));
        Platform::fakeDarwin(false);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        file_put_contents($this->dir . '/app.pem', $pem);
        putenv('RUNNERDECK_GITHUB_APP_ID=11');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID=22');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE=' . $this->dir . '/app.pem');
        $this->assertStringContainsString(
            'GitHub App',
            (string) \RunnerDeck\GitHub\GithubAuth::staleWarning(true, false, 'HTTP 401')
        );

        $this->swallow();
        \RunnerDeck\Http\Halt::fake(static function (): never {
            throw new \RuntimeException('halted');
        });
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        \RunnerDeck\Security\Auth::requirePageAccess();
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        putenv('RUNNERDECK_IP_ALLOWLIST=203.0.113.9');
        $this->expectOutputRegex('/TOTP login is required/');
        try {
            \RunnerDeck\Security\Auth::rejectApiIfProtected();
        } catch (\RuntimeException $e) {
            $this->assertSame('halted', $e->getMessage());
        }
        \RunnerDeck\Http\Halt::fake(static function (): never {
            throw new \RuntimeException('halted');
        });
        try {
            \RunnerDeck\Security\Auth::rejectPlainIfProtected();
        } catch (\RuntimeException $e) {
            $this->assertSame('halted', $e->getMessage());
        }
        $this->assertFalse(\RunnerDeck\Security\Auth::attemptRecovery(''));
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        $this->assertFalse(\RunnerDeck\Security\Auth::attemptRecovery('----'));
        $_SESSION['issued_recovery_codes'] = 'nope';
        $this->assertSame([], \RunnerDeck\Security\Auth::takeIssuedRecoveryCodes());
        file_put_contents($this->dir . '/auth_session.json', '"nope"');
        $this->assertSame(0, \RunnerDeck\Security\Auth::sessionEpoch());
        file_put_contents($this->dir . '/auth_recovery.json', '{"hashes":"nope"}');
        $this->assertSame(0, \RunnerDeck\Security\Auth::recoveryCodesRemaining());

        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot . '/bin', 0755, true);
        file_put_contents($slot . '/bin/Runner.Listener', 'x');
        file_put_contents($slot . '/.runner', "1\n");
        $configured = new RunnerInfo(
            id: 'runner-1',
            dir: $slot,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );
        $n = 0;
        RunnerPool::fakeCheckProcess(static function () use (&$n): array {
            $n++;
            return $n === 1 ? [false, null] : [true, 7];
        });
        RunnerPool::fakeLiveListeners([]);
        $restarted = \RunnerDeck\Lifecycle\RollingRestart::run([$configured], true, 0);
        $this->assertTrue($restarted['ok']);
        restore_error_handler();
    }

    private function swallow(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            return str_contains($message, 'header') || str_contains($message, 'Cannot');
        });
    }

    /** @param array{os: string, architecture: string, filename: string, download_url: string} $download */
    private function writePackage(string $dest): void
    {
        $stage = $this->dir . '/pkg';
        if (!is_dir($stage . '/bin')) {
            mkdir($stage . '/bin', 0755, true);
            file_put_contents($stage . '/bin/Runner.Listener', "echo ok\n");
            file_put_contents($stage . '/config.sh', "#!/bin/sh\n");
            file_put_contents($stage . '/run.sh', "#!/bin/sh\n");
        }
        $proc = proc_open(
            ['tar', 'czf', $dest, '-C', $stage, '.'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($proc)) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
    }

    /** @return array{os: string, architecture: string, filename: string, download_url: string} */
    private function download(): array
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
}
