<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CoverageEdgesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-edges-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        mkdir($this->dir . '/pool', 0755, true);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\System\Shell::fake(null);
        \RunnerDeck\GitHub\GithubApp::fakeToken(null);
        \RunnerDeck\GitHub\AgentTarget::fake(null);
        \RunnerDeck\Host\JobRuntime::fake(null);
        \RunnerDeck\Alerting\SyslogSink::fakeSend(null);
        \RunnerDeck\Alerting\WebhookOutbox::fakeNow(null);
        \RunnerDeck\Backup\HostBundleSnapshot::fakeNow(null);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(null);
        \RunnerDeck\Host\HostPressure::fakeDisk(null);
        \RunnerDeck\Host\HostPressure::fakeRam(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_GITHUB_APP_ID');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_SCALE_TO_QUEUE');
        putenv('RUNNERDECK_IDLE_STOP_MINUTES');
        putenv('RUNNERDECK_AUTO_RESTART');
        putenv('RUNNERDECK_SYSLOG');
        putenv('RUNNERDECK_SYSLOG_SOCKET');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT');
        putenv('GH_TOKEN');
        putenv('GITHUB_TOKEN');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testGithubAppMintsCachesAndRejectsABadToken(): void
    {
        $pem = $this->privateKey();
        putenv('RUNNERDECK_GITHUB_APP_ID=12');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID=34');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE=' . $this->dir . '/app.pem');
        file_put_contents($this->dir . '/app.pem', $pem);
        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => json_encode([
                'token' => 'ghs_minted',
                'expires_at' => gmdate('c', time() + 3600),
            ]),
            'stderr' => '',
        ]);

        $this->assertSame('ghs_minted', \RunnerDeck\GitHub\GithubApp::token());
        $this->assertSame('ghs_minted', \RunnerDeck\GitHub\GithubApp::token());
        \RunnerDeck\GitHub\GithubApp::applyToEnv();
        $this->assertSame('ghs_minted', getenv('GH_TOKEN'));

        file_put_contents($this->dir . '/github_app_token.json', '{"token":""}');
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'no']);
        $this->assertNull(\RunnerDeck\GitHub\GithubApp::token());

        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 0, 'stdout' => '{', 'stderr' => '']);
        $this->assertNull(\RunnerDeck\GitHub\GithubApp::token());

        file_put_contents(
            $this->dir . '/app.pem',
            "-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----\n"
        );
        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => json_encode(['token' => 'ghs_other', 'expires_at' => '']),
            'stderr' => '',
        ]);
        $this->assertNull(\RunnerDeck\GitHub\GithubApp::token());

        $this->assertTrue(\RunnerDeck\GitHub\GithubApp::savePrivateKey($pem));
        $this->assertTrue(\RunnerDeck\GitHub\GithubApp::hasPrivateKey());
        putenv('RUNNERDECK_GITHUB_APP_ID=0');
        $this->assertNull(\RunnerDeck\GitHub\GithubApp::appId());
        \RunnerDeck\GitHub\GithubApp::fakeToken(fn (): string => '');
        \RunnerDeck\GitHub\GithubApp::applyToEnv();
    }

    #[RunInSeparateProcess]
    public function testRunnerListCacheServesFreshAndStaleCopies(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $row = [
            'id' => 1,
            'status' => 'online',
            'busy' => false,
            'labels' => ['self-hosted'],
            'os' => 'Linux',
        ];
        $path = $this->dir . '/gh_runners_cache.json';
        file_put_contents($path, json_encode(['fetched_at' => time(), 'runners' => ['acme-1' => $row]]));
        $fresh = \RunnerDeck\GitHub\GithubClient::listRunners();
        $this->assertSame(1, $fresh['acme-1']['id']);

        file_put_contents($path, json_encode(['fetched_at' => time() - 30, 'runners' => ['acme-1' => $row]]));
        $lock = fopen($this->dir . '/gh_runners_cache.json.lock', 'c');
        $this->assertIsResource($lock);
        flock($lock, LOCK_EX);
        try {
            $stale = \RunnerDeck\GitHub\GithubClient::listRunners();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertSame('online', $stale['acme-1']['status']);

        file_put_contents($path, 'not-json');
        $read = new \ReflectionMethod(\RunnerDeck\GitHub\GithubClient::class, 'readRunnerListCache');
        $this->assertNull($read->invoke(null, false));
        file_put_contents($path, '');
        $this->assertNull($read->invoke(null, true));

        $write = new \ReflectionMethod(\RunnerDeck\GitHub\GithubClient::class, 'writeRunnerListCache');
        $write->invoke(null, ['acme-1' => $row]);
        $this->assertSame(1, $read->invoke(null, false)['acme-1']['id']);

        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        $write->invoke(null, ['acme-1' => $row]);
        $open = new \ReflectionMethod(\RunnerDeck\GitHub\GithubClient::class, 'openRunnerListLock');
        $this->assertNull($open->invoke(null));
    }

    #[RunInSeparateProcess]
    public function testAuthStatusReportsAMintFailureAndAReadFailure(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_GITHUB_APP_ID=12');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID=34');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE=' . $this->dir . '/app.pem');
        file_put_contents($this->dir . '/app.pem', $this->privateKey());
        \RunnerDeck\GitHub\GithubApp::fakeToken(fn (): ?string => null);
        $status = \RunnerDeck\GitHub\GithubClient::authStatus();
        $this->assertFalse($status->toArray()['logged_in']);

        \RunnerDeck\GitHub\GithubApp::fakeToken(fn (): string => 'ghs_ok');
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'denied']);
        $status = \RunnerDeck\GitHub\GithubClient::authStatus();
        $this->assertTrue($status->loggedIn);
        $this->assertFalse($status->orgAccessOk);
    }

    #[RunInSeparateProcess]
    public function testAgentTargetFetchesAndKeepsAStaleCache(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => "HTTP/2.0 200 OK\n\n" . json_encode([$this->download()]),
            'stderr' => '',
        ]);
        $this->assertSame('2.329.0', \RunnerDeck\GitHub\AgentTarget::latest());

        $path = $this->dir . '/agent_target.json';
        file_put_contents($path, json_encode(['version' => '2.1.0', 'fetched_at' => time() - 7200]));
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'down']);
        $this->assertSame('2.1.0', \RunnerDeck\GitHub\AgentTarget::latest());

        file_put_contents($path, 'not-json');
        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => "HTTP/2.0 200 OK\n\n" . json_encode([[
                'os' => $this->download()['os'],
                'architecture' => $this->download()['architecture'],
                'filename' => 'actions-runner-no-version.tar.gz',
                'download_url' => 'https://example.invalid/none',
            ]]),
            'stderr' => '',
        ]);
        $this->assertNull(\RunnerDeck\GitHub\AgentTarget::latest());
    }

    #[RunInSeparateProcess]
    public function testUpdateCheckAndJobRuntimeUseTheFakedShell(): void
    {
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 0, 'stdout' => '{"tag_name":""}', 'stderr' => '']);
        $empty = \RunnerDeck\GitHub\UpdateCheck::check();
        $this->assertSame('unexpected response from GitHub', $empty['error']);

        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => json_encode(['tag_name' => 'v9.9.9']),
            'stderr' => '',
        ]);
        $newer = \RunnerDeck\GitHub\UpdateCheck::check();
        $this->assertTrue($newer['update_available']);
        $this->assertSame('9.9.9', $newer['latest']);

        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 0, 'stdout' => "v22.1.0\n", 'stderr' => '']);
        $this->assertSame('v22.1.0', \RunnerDeck\Host\JobRuntime::version());
        $this->assertSame('v22.1.0', \RunnerDeck\Host\JobRuntime::version());
        $this->assertSame('node v22.1.0 — jobs inherit this', \RunnerDeck\Host\JobRuntime::line());
        \RunnerDeck\Host\JobRuntime::fake(null);
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 1, 'stdout' => 'no', 'stderr' => '']);
        $this->assertNull(\RunnerDeck\Host\JobRuntime::version());
    }

    #[RunInSeparateProcess]
    public function testMetricsIncludeARunningSlot(): void
    {
        mkdir($this->dir . '/pool/runner-1', 0755, true);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn (): array => [true, getmypid()]);
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'ps') {
                return [
                    'code' => 0,
                    'stdout' => getmypid() . " 1.5 2048 9\n",
                    'stderr' => '',
                ];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        \RunnerDeck\Host\HostPressure::fakeDisk(fn (): array => [4096, 8192]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn (): int => 2048);
        $text = \RunnerDeck\Telemetry\Metrics::text();
        $this->assertStringContainsString('runnerdeck_slot_running{id="runner-1"} 1', $text);
        $this->assertStringContainsString('runnerdeck_slot_cpu_percent{id="runner-1"}', $text);
        $this->assertStringContainsString('runnerdeck_slot_rss_bytes{id="runner-1"}', $text);
        $this->assertStringContainsString('runnerdeck_disk_free_bytes', $text);
        $this->assertStringContainsString('runnerdeck_ram_available_bytes', $text);
    }

    #[RunInSeparateProcess]
    public function testWatcherTickStopsNothingWhenTheQueueIsEmpty(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_SCALE_TO_QUEUE=1');
        putenv('RUNNERDECK_IDLE_STOP_MINUTES=15');
        file_put_contents($this->dir . '/queue_cache.json', json_encode([
            'fetched_at' => time(),
            'payload' => ['ok' => true, 'count' => 0, 'jobs' => [], 'live' => [], 'recent' => []],
        ]));
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn (): array => [false, null]);
        $out = \RunnerDeck\Lifecycle\AutoRestart::tick();
        $this->assertSame([], $out);
    }

    #[RunInSeparateProcess]
    public function testWebhookOutboxRetriesAndGivesUp(): void
    {
        \RunnerDeck\Alerting\WebhookOutbox::fakeNow(1_700_000_000);
        \RunnerDeck\Alerting\WebhookOutbox::enqueue(
            'https://example.invalid/hook',
            ['ok' => false],
            'crash',
            'down',
            'runner-1',
            'first'
        );
        \RunnerDeck\Alerting\WebhookOutbox::enqueue(
            'https://example.invalid/hook',
            ['ok' => false],
            'crash',
            'down',
            'runner-1',
            'second'
        );
        \RunnerDeck\Alerting\WebhookOutbox::fakeNow(1_700_000_010);
        \RunnerDeck\Alerting\WebhookOutbox::flush(fn (): bool => false);
        $this->assertNull(\RunnerDeck\Alerting\WebhookOutbox::warning());

        $path = $this->dir . '/webhook_outbox.json';
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);
        $data['items'][0]['attempts'] = 7;
        $data['items'][0]['next_at'] = 1;
        file_put_contents($path, json_encode($data));
        \RunnerDeck\Alerting\WebhookOutbox::fakeNow(1_700_000_100);
        \RunnerDeck\Alerting\WebhookOutbox::flush(fn (): bool => false);
        $this->assertStringContainsString('gave up', (string) \RunnerDeck\Alerting\WebhookOutbox::warning());

        file_put_contents($this->dir . '/webhook_gave_up.json', 'not-json');
        $this->assertNull(\RunnerDeck\Alerting\WebhookOutbox::warning());
        $id = hash('sha256', 'nope');
        $this->assertFalse(\RunnerDeck\Alerting\WebhookOutbox::drop($id));
    }

    #[RunInSeparateProcess]
    public function testRosterSnapshotAndLayoutEdges(): void
    {
        file_put_contents($this->dir . '/agent_roster.json', 'not-json');
        $_SERVER['REQUEST_TIME_FLOAT'] = '1';
        $this->assertSame([], \RunnerDeck\GitHub\AgentRoster::names());
        $_SERVER['REQUEST_TIME_FLOAT'] = '2';
        file_put_contents($this->dir . '/agent_roster.json', json_encode(['acme-1', '', 'acme-1', 3]));
        $this->assertSame(['acme-1'], \RunnerDeck\GitHub\AgentRoster::names());
        \RunnerDeck\GitHub\AgentRoster::remember('');
        \RunnerDeck\GitHub\AgentRoster::remember('acme-1');

        $this->assertNull(\RunnerDeck\Backup\HostBundleSnapshot::pathFor('nope'));
        $this->assertNull(\RunnerDeck\Backup\HostBundleSnapshot::pathFor('2026-01-01'));
        $this->assertFalse(\RunnerDeck\Backup\HostBundleSnapshot::delete('2026-01-01'));
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        \RunnerDeck\Backup\HostBundleSnapshot::fakeNow(fn (): int => strtotime('2026-01-02T00:00:00Z'));
        \RunnerDeck\Backup\HostBundleSnapshot::tick();
        $this->assertNotNull(\RunnerDeck\Backup\HostBundleSnapshot::pathFor('2026-01-02'));
        \RunnerDeck\Backup\HostBundleSnapshot::tick();

        $blocked = $this->dir . '/not-a-dir';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        \RunnerDeck\Backup\HostBundleSnapshot::fakeNow(fn (): int => strtotime('2026-01-03T00:00:00Z'));
        \RunnerDeck\Backup\HostBundleSnapshot::tick();

        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        mkdir($this->dir . '/pool/runner-1');
        file_put_contents($this->dir . '/pool/not-a-slot', 'x');
        $this->assertSame(1, \RunnerDeck\Pool\PoolLayout::slotCount());
        $this->assertStringContainsString('unknown', \RunnerDeck\Pool\PoolLayout::spaceSentence(2, null));

        $missing = \RunnerDeck\Pool\ProcessControl::removeDirectory($this->dir . '/missing-slot');
        $this->assertTrue($missing['ok']);

        $this->expectOutputRegex('/manifest.webmanifest/');
        \RunnerDeck\Presentation\Layout::htmlOpen('Hello', true);
        \RunnerDeck\Presentation\Layout::htmlClose(['assets/js/theme.js']);
    }

    #[RunInSeparateProcess]
    public function testLogoutFormAndScopeChipRender(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $this->expectOutputRegex('/logout.php/');
        \RunnerDeck\Presentation\Layout::logoutForm('tok');
        \RunnerDeck\Presentation\Layout::scopeChip();
        putenv('RUNNERDECK_SCOPE=repo');
        \RunnerDeck\Presentation\Layout::scopeChip();
    }

    #[RunInSeparateProcess]
    public function testSyslogWritesToALocalSocket(): void
    {
        $sock = $this->dir . '/log.sock';
        $server = stream_socket_server('unix://' . $sock, $errno, $errstr);
        $this->assertIsResource($server);
        putenv('RUNNERDECK_SYSLOG=1');
        putenv('RUNNERDECK_SYSLOG_SOCKET=' . $sock);
        \RunnerDeck\Alerting\SyslogSink::emit('crash.loop', "line\n");
        $client = stream_socket_accept($server, 1);
        $this->assertIsResource($client);
        $line = (string) fread($client, 1024);
        fclose($client);
        fclose($server);
        $this->assertStringContainsString('crash.loop', $line);

        putenv('RUNNERDECK_SYSLOG_SOCKET=' . $this->dir . '/missing.sock');
        \RunnerDeck\Alerting\SyslogSink::emit('crash.loop', 'again');
        $plain = $this->dir . '/plain-file';
        file_put_contents($plain, 'not-a-socket');
        putenv('RUNNERDECK_SYSLOG_SOCKET=' . $plain);
        \RunnerDeck\Alerting\SyslogSink::emit('crash.loop', 'third');
    }

    #[RunInSeparateProcess]
    public function testBootstrapLoadsTheEnvironment(): void
    {
        $before = count(spl_autoload_functions() ?: []);
        require dirname(__DIR__, 2) . '/src/bootstrap.php';
        $fns = spl_autoload_functions() ?: [];
        $this->assertGreaterThan($before, count($fns));
        $loader = $fns[array_key_last($fns)];
        $this->assertIsCallable($loader);
        $loader('NotRunnerDeck\\Nope');
        $loader('RunnerDeck\\No\\SuchClass');
    }

    #[RunInSeparateProcess]
    public function testRollingUpgradeSkipsCurrentAndStopsOnAFailedInstall(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot, 0755, true);
        $runner = new \RunnerDeck\Pool\RunnerInfo(
            id: 'runner-1',
            dir: $slot,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
            agentVersion: '2.329.0',
        );
        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => "HTTP/2.0 200 OK\n\n" . json_encode([$this->download()]),
            'stderr' => '',
        ]);
        $skipped = \RunnerDeck\Lifecycle\RollingUpgrade::run([$runner], true, 0);
        $this->assertTrue($skipped['ok']);
        $this->assertStringContainsString('already on 2.329.0', $skipped['message']);

        $behind = new \RunnerDeck\Pool\RunnerInfo(
            id: 'runner-1',
            dir: $slot,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
            agentVersion: '2.1.0',
        );
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'curl') {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'curl failed'];
            }
            return [
                'code' => 0,
                'stdout' => "HTTP/2.0 200 OK\n\n" . json_encode([$this->download()]),
                'stderr' => '',
            ];
        });
        $failed = \RunnerDeck\Lifecycle\RollingUpgrade::run([$behind], true, 0);
        $this->assertFalse($failed['ok']);
        $this->assertStringContainsString('download', $failed['message']);

        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => "HTTP/2.0 200 OK\n\n" . json_encode([$this->download()]),
            'stderr' => '',
        ]);
        $waiting = \RunnerDeck\Lifecycle\RollingUpgrade::run([$behind], false, 0);
        $this->assertFalse($waiting['ok']);
    }

    #[RunInSeparateProcess]
    public function testQueueSnapshotAndRunnerListEdges(): void
    {
        putenv('RUNNERDECK_SCOPE=org');
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 0, 'stdout' => '', 'stderr' => '']);
        $snap = \RunnerDeck\GitHub\QueuedJobs::snapshot();
        $this->assertFalse($snap['ok']);
        $this->assertStringContainsString('RUNNERDECK_ORG', $snap['message']);

        putenv('RUNNERDECK_ORG=acme');
        file_put_contents($this->dir . '/queue_cache.json', 'not-json');
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            if (in_array('status', $cmd, true)) {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'down'];
        });
        $again = \RunnerDeck\GitHub\QueuedJobs::snapshot();
        $this->assertTrue($again['ok']);
        $this->assertSame(0, $again['count']);

        $page = json_encode([
            'runners' => [[
                'id' => 3,
                'name' => 'acme-3',
                'status' => 'online',
                'busy' => false,
                'labels' => ['linux'],
            ]],
        ]);
        $decoded = \RunnerDeck\GitHub\GithubClient::decodeRunnerList($page . "\n" . $page);
        $this->assertSame(3, $decoded['acme-3']['id']);
        $this->expectException(\RuntimeException::class);
        \RunnerDeck\GitHub\GithubClient::decodeRunnerList("{\"nope\":true}\n");
    }

    #[RunInSeparateProcess]
    public function testEmptyDownloadListFallsBackToTheRelease(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        $calls = 0;
        \RunnerDeck\System\Shell::fake(function () use (&$calls): array {
            $calls++;
            $body = $calls === 1
                ? '[]'
                : json_encode(['assets' => [[
                    'name' => 'actions-runner-linux-x64-2.1.0.tar.gz',
                    'browser_download_url' => 'https://example.invalid/r',
                    'digest' => 'sha256:abc',
                ]]]);
            return ['code' => 0, 'stdout' => "HTTP/2.0 200 OK\n\n" . $body, 'stderr' => ''];
        });
        $downloads = \RunnerDeck\GitHub\GithubClient::listRunnerDownloads();
        $this->assertSame('actions-runner-linux-x64-2.1.0.tar.gz', $downloads[0]['filename']);
        $this->assertSame('abc', $downloads[0]['sha256_checksum']);

        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => "HTTP/2.0 200 OK\n\nnot-json",
            'stderr' => '',
        ]);
        $this->expectException(\RuntimeException::class);
        \RunnerDeck\GitHub\GithubClient::getJson('repos/acme/widgets');
    }

    #[RunInSeparateProcess]
    public function testIdleSlotLogPruneAndListenerRediscovery(): void
    {
        putenv('RUNNERDECK_LOG_RETENTION_DAYS=1');
        $slot = $this->dir . '/pool/runner-1';
        $base = $this->dir . '/pool/runner-base';
        mkdir($slot . '/_diag/nested', 0755, true);
        mkdir($base, 0755, true);
        $old = $slot . '/_diag/nested/worker.log';
        file_put_contents($old, 'old');
        touch($old, time() - 3 * 86400);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn (): array => [false, null]);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([$base => 4242]);
        $this->assertSame(1, \RunnerDeck\Pool\LogRetention::tick());
        $this->assertFileDoesNotExist($old);
        $found = \RunnerDeck\Pool\RunnerPool::discover(0);
        $this->assertTrue($found['runner-base']->localRunning);
        $this->assertSame(4242, $found['runner-base']->pid);
    }

    #[RunInSeparateProcess]
    public function testWebhookOutboxDropsOneAndRetriesTheOther(): void
    {
        \RunnerDeck\Alerting\WebhookOutbox::fakeNow(1_700_000_000);
        \RunnerDeck\Alerting\WebhookOutbox::enqueue('https://example.invalid/a', [], 'crash', 'one', null);
        \RunnerDeck\Alerting\WebhookOutbox::enqueue('https://example.invalid/a', [], 'crash', 'two', null);
        $data = json_decode((string) file_get_contents($this->dir . '/webhook_outbox.json'), true);
        $this->assertTrue(\RunnerDeck\Alerting\WebhookOutbox::drop($data['items'][0]['id']));
        \RunnerDeck\Alerting\WebhookOutbox::fakeNow(1_700_000_100);
        \RunnerDeck\Alerting\WebhookOutbox::flush(fn (): bool => false);
        $left = json_decode((string) file_get_contents($this->dir . '/webhook_outbox.json'), true);
        $this->assertSame(1, $left['items'][0]['attempts']);

        for ($i = 0; $i < 41; $i++) {
            \RunnerDeck\Alerting\WebhookOutbox::enqueue('https://example.invalid/a', [], 'crash', 'm' . $i, null);
        }
        $capped = json_decode((string) file_get_contents($this->dir . '/webhook_outbox.json'), true);
        $this->assertCount(40, $capped['items']);
        file_put_contents($this->dir . '/webhook_outbox.json', 'not-json');
        $this->assertSame(0, \RunnerDeck\Alerting\WebhookOutbox::pendingCount());
    }

    #[RunInSeparateProcess]
    public function testSmallBranchesAcrossLoggingFlagsAndHistory(): void
    {
        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot, 0755, true);
        \RunnerDeck\Pool\SlotFlags::setEphemeral($slot, false);
        \RunnerDeck\Pool\SlotFlags::setExtraLabels($slot, []);
        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        \RunnerDeck\Pool\SlotFlags::setEphemeral($blocked . '/slot', true);
        \RunnerDeck\Pool\SlotFlags::setExtraLabels($blocked . '/slot', ['gpu']);

        $this->assertStringContainsString('127.0.0.1', \RunnerDeck\Security\HostingWarnings::proxyGapText());
        $this->assertStringContainsString('no crash events', \RunnerDeck\Telemetry\History::barSvg([]));
        $this->assertNull(\RunnerDeck\Telemetry\History::cachedChart(1));
        $this->assertSame([], \RunnerDeck\GitHub\QueuedJobs::pickQueuedJobs(['jobs' => 'no'], 'acme/widgets', 'lbl'));
        $this->assertSame([], \RunnerDeck\GitHub\QueuedJobs::pickLiveJobs(['jobs' => 'no'], 'acme/widgets'));
        $this->assertSame([], \RunnerDeck\GitHub\QueuedJobs::pickRecentJobs(['jobs' => 'no'], 'acme/widgets'));

        \RunnerDeck\Alerting\WebhookOutbox::enqueue('https://example.invalid/a', [], 'crash', 'keep', 'runner-1');
        \RunnerDeck\Alerting\WebhookOutbox::forget('https://example.invalid/a', 'crash', 'keep', 'runner-1');
        file_put_contents($this->dir . '/webhook_gave_up.json', json_encode(['at' => 1, 'event' => '']));
        $this->assertNull(\RunnerDeck\Alerting\WebhookOutbox::warning());
        file_put_contents($this->dir . '/webhook_gave_up.json', json_encode(['at' => time(), 'event' => '']));
        $this->assertStringContainsString('crash webhook', (string) \RunnerDeck\Alerting\WebhookOutbox::warning());

        \RunnerDeck\Logging\AppLog::error('coverage', 'long', [
            'runner' => 'runner-1',
            'stderr' => str_repeat('e', 5000),
        ]);
        $recent = \RunnerDeck\Logging\AppLog::recent(5);
        $this->assertSame('runner-1', $recent[array_key_last($recent)]['runner'] ?? null);

        putenv('RUNNERDECK_GH_BIN=' . PHP_BINARY);
        $this->assertSame(PHP_BINARY, \RunnerDeck\Configuration\Config::ghBinary());
        file_put_contents($this->dir . '/hosts.yml', "github.com:\n");
        putenv('GH_CONFIG_DIR=' . $this->dir);
        $this->assertNull(\RunnerDeck\Configuration\Config::ghConfigDir());

        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        file_put_contents($this->dir . '/gh_runners_cache.json', json_encode([
            'fetched_at' => time(),
            'runners' => [],
        ]));
        $busy = \RunnerDeck\Presentation\Dashboard::isBusy('missing-runner');
        $this->assertFalse($busy[0]);
        $this->assertStringContainsString('no GitHub record', (string) $busy[2]);

        $this->expectOutputRegex('/<\/div>/');
        \RunnerDeck\Presentation\Layout::logoutForm('tok');
        \RunnerDeck\Presentation\Layout::stickyHeadEnd();
        putenv('RUNNERDECK_REPO');
        putenv('RUNNERDECK_SCOPE=repo');
        \RunnerDeck\Presentation\Layout::scopeChip();

        \RunnerDeck\Configuration\Config::bootstrapEnv();
        \RunnerDeck\Configuration\Config::bootstrapEnv();
        putenv('RUNNERDECK_SYSLOG_SOCKET');
        $this->assertSame('/dev/log', \RunnerDeck\Configuration\Config::syslogSocket());

        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/missing/pool');
        \RunnerDeck\Host\HostPressure::fakeDisk(null);
        \RunnerDeck\Host\HostPressure::snapshot();
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        \RunnerDeck\Host\HostPressure::checkAlert();
    }

    #[RunInSeparateProcess]
    public function testDashboardHistoryAndRestartBranches(): void
    {
        $slot = $this->dir . '/pool/runner-1';
        mkdir($slot . '/bin', 0755, true);
        file_put_contents($slot . '/.runnerdeck-ephemeral', "1\n");

        \RunnerDeck\Alerting\WebhookOutbox::enqueue('https://example.invalid/a', [], 'crash', 'keep', 'runner-1');
        \RunnerDeck\Alerting\WebhookOutbox::enqueue('https://example.invalid/b', [], 'crash', 'drop', 'runner-1');
        \RunnerDeck\Alerting\WebhookOutbox::forget('https://example.invalid/b', 'crash', 'drop', 'runner-1');
        $this->assertSame(1, \RunnerDeck\Alerting\WebhookOutbox::pendingCount());

        $bundle = \RunnerDeck\Backup\HostBundle::class;
        $this->assertFalse($bundle::import(['kind' => 'other'])['ok']);
        $this->assertFalse($bundle::import(['kind' => $bundle::KIND, 'version' => 9])['ok']);
        $this->assertFalse($bundle::import(['kind' => $bundle::KIND, 'version' => $bundle::VERSION])['ok']);

        file_put_contents($this->dir . '/app.log', '');
        $this->assertSame([], \RunnerDeck\Logging\AppLog::recent(5));
        $history = $this->dir . '/history.sqlite';
        putenv('RUNNERDECK_HISTORY_FILE=' . $history);
        file_put_contents(dirname($history) . '/history_chart.json', json_encode([
            'sample_ts' => 5,
            'built_at' => 0,
            'view' => ['cpu_svg' => '<svg></svg>', 'mem_svg' => '<svg></svg>'],
        ]));
        $this->assertNull(\RunnerDeck\Telemetry\History::cachedChart(5));
        \RunnerDeck\Telemetry\History::record(1.0, 1024);
        @unlink(dirname($history) . '/history_sample_ts');
        \RunnerDeck\Telemetry\History::record(1.0, 1024);

        \RunnerDeck\Presentation\Dashboard::notifyCrashes([[
            'id' => 'runner-1',
            'agent_name' => 'runner-1',
            'just_mismatch_flagged' => true,
        ]]);

        putenv('RUNNERDECK_ORG');
        $this->assertSame([], \RunnerDeck\Lifecycle\AutoRestart::tick());
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_AUTO_RESTART=1');
        \RunnerDeck\Lifecycle\AutoRestart::tick();

        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.invalid/hook');
        putenv('RUNNERDECK_DISK_WEBHOOK_THRESHOLD=1');
        file_put_contents($this->dir . '/disk_alert.json', json_encode(['slots' => ['gone' => true]]));
        \RunnerDeck\Alerting\DiskAlert::check([['id' => 'runner-1', 'disk_kb' => 1]], null);

        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 0, 'stdout' => 'nope', 'stderr' => '']);
        $snap = \RunnerDeck\Presentation\Dashboard::snapshot(0);
        $this->assertStringContainsString('cannot read', (string) $snap['health']['message']);

        $blocked = $this->dir . '/blocked';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/settings.json');
        \RunnerDeck\Logging\AppLog::error('coverage', 'blocked');
        \RunnerDeck\Alerting\WebhookOutbox::enqueue('https://example.invalid/c', [], 'crash', 'late', null);
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

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        return $pem;
    }
}
