<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\Doctor;
use RunnerDeck\Configuration\Config;
use RunnerDeck\GitHub\GithubApp;
use RunnerDeck\GitHub\GithubClient;
use RunnerDeck\Host\HostPressure;
use RunnerDeck\Pool\Provisioner;
use RunnerDeck\System\Platform;
use RunnerDeck\System\Shell;
use RuntimeException;

final class DoctorTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $env = [
        'RUNNERDECK_POOL_DIR',
        'RUNNERDECK_SETTINGS_FILE',
        'RUNNERDECK_ORG',
        'RUNNERDECK_REPO',
        'RUNNERDECK_SCOPE',
        'RUNNERDECK_BIND_HOST',
        'RUNNERDECK_TRUST_PROXY',
        'RUNNERDECK_AUTH_TOTP_SECRET',
        'RUNNERDECK_GITHUB_APP_ID',
        'RUNNERDECK_GITHUB_APP_INSTALLATION_ID',
        'RUNNERDECK_GITHUB_APP_KEY_FILE',
        'RUNNERDECK_IP_ALLOWLIST',
        'RUNNERDECK_REQUIRE_MTLS',
        'RUNNERDECK_NO_BANNER',
    ];

    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-doctor-' . uniqid();
        mkdir($this->dir . '/pool', 0755, true);
        foreach ($this->env as $name) {
            $value = getenv($name);
            $this->saved[$name] = $value === false ? null : $value;
            putenv($name);
        }
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => '', 'stderr' => '']);
        HostPressure::fakeDisk(static fn (): array => [20 * 1024 * 1024 * 1024, 40 * 1024 * 1024 * 1024]);
        HostPressure::fakeRam(static fn (): int => 8 * 1024 * 1024);
    }

    protected function tearDown(): void
    {
        Shell::fake(null);
        HostPressure::fakeDisk(null);
        HostPressure::fakeRam(null);
        Platform::fakeWindows(null);
        Provisioner::fakeHost(null, null);
        GithubClient::fakeFetch(null);
        GithubApp::fakeToken(null);
        Config::fakeGhBinary(null);
        Doctor::fakePhpVersion(null);
        Doctor::fakeBanner(false);
        foreach ($this->saved as $name => $value) {
            if ($value === null) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testJsonListsTheSameChecksAsTheTextReport(): void
    {
        [$textCode, $text] = $this->doctor(['doctor']);
        [$jsonCode, $json] = $this->doctor(['doctor', '--json', '--quiet']);
        $data = json_decode($json, true);

        $this->assertSame($textCode, $jsonCode);
        $this->assertIsArray($data);
        $this->assertSame($textCode === 0, $data['ok']);
        $this->assertStringStartsWith('{', $json);
        $this->assertStringNotContainsString('RunnerDeck doctor', $json);
        $this->assertStringNotContainsString('[ OK ]', $json);

        $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
        preg_match_all('/\[( OK |WARN|FAIL)\] (.+)$/m', $plain, $rows, PREG_SET_ORDER);
        $this->assertNotEmpty($rows);
        $this->assertCount(count($rows), $data['checks']);

        foreach ($rows as $i => $row) {
            $parts = explode(' — ', $row[2], 2);
            $check = $data['checks'][$i];
            $this->assertSame(trim($parts[0]), $check['name']);
            $this->assertSame($parts[1] ?? '', $check['detail']);
            $this->assertIsBool($check['ok']);
            if ($row[1] === 'FAIL') {
                $this->assertFalse($check['ok']);
            } else {
                $this->assertTrue($check['ok']);
            }
        }
    }

    public function testAnUnconfiguredScopeIsAWarning(): void
    {
        [, $json] = $this->doctor(['doctor', '--json']);
        $data = json_decode($json, true);
        $scope = null;
        foreach ($data['checks'] as $check) {
            if ($check['name'] === 'scope') {
                $scope = $check;
            }
        }
        $this->assertNotNull($scope);
        $this->assertTrue($scope['ok']);
        $this->assertStringContainsString('not configured', $scope['detail']);
    }

    public function testAnUnwritablePoolIsAFailure(): void
    {
        putenv('RUNNERDECK_POOL_DIR=/no/such/runnerdeck-doctor-pool/runners');
        [$code, $json] = $this->doctor(['doctor', '--json']);
        $data = json_decode($json, true);
        $this->assertSame(1, $code);
        $this->assertFalse($data['ok']);
        $pool = null;
        foreach ($data['checks'] as $check) {
            if ($check['name'] === 'pool directory' && $check['ok'] === false) {
                $pool = $check;
            }
        }
        $this->assertNotNull($pool);
        $this->assertStringContainsString('is not writable', $pool['detail']);
    }

    public function testTextReportNamesEveryCheckAndKeepsAWarningNonFatal(): void
    {
        Doctor::fakePhpVersion('8.5.13');
        $this->fakeShell();
        [$code, $text] = $this->doctor(['doctor']);
        $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);

        $this->assertSame(0, $code);
        $this->assertSame($this->expectedTextCheckNames(), $this->textCheckNames($plain));
        $this->assertStringContainsString('[WARN] scope — not configured', $plain);
        $this->assertStringContainsString('All required checks passed', $plain);
    }

    public function testTextReportFailsWhenGhIsNotLoggedIn(): void
    {
        Doctor::fakePhpVersion('8.5.13');
        $this->fakeShell(fn (array $cmd): ?array => $this->authStatus($cmd, 1, '', 'not logged in'));
        [$code, $text] = $this->doctor(['doctor']);
        $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('[FAIL] gh auth status — not logged in', $plain);
        $this->assertStringContainsString('check(s) failed', $plain);
    }

    public function testPhpVersionWarningsAndAnOldInterpreter(): void
    {
        Doctor::fakePhpVersion('8.0.30');
        $old = $this->checks();
        $this->assertFalse($old['PHP version']['ok']);

        Doctor::fakePhpVersion('8.1.2');
        $eol = $this->checks();
        $this->assertTrue($eol['PHP security']['ok']);
        $this->assertStringContainsString('end-of-life', $eol['PHP security']['detail']);

        Doctor::fakePhpVersion('8.5.0');
        $behind = $this->checks();
        $this->assertTrue($behind['PHP security']['ok']);
        $this->assertStringContainsString('below 8.5.12', $behind['PHP security']['detail']);
    }

    public function testMissingToolsWindowsToolsAndGhAuth(): void
    {
        $this->fakeShell(fn (array $cmd): ?array => $this->missingWhich($cmd, ['bash', 'curl', 'tar']));
        $missing = $this->checks();
        $this->assertFalse($missing['bash']['ok']);
        $this->assertFalse($missing['curl']['ok']);
        $this->assertFalse($missing['tar']['ok']);

        Platform::fakeWindows(true);
        $this->fakeShell(fn (array $cmd): ?array => $this->missingWhich($cmd, ['taskkill']));
        $windows = $this->checks();
        $this->assertTrue($windows['powershell.exe']['ok']);
        $this->assertFalse($windows['taskkill']['ok']);
        Platform::fakeWindows(false);

        $this->fakeShell(fn (array $cmd): ?array => $this->authStatus($cmd, 1, '', 'not logged in'));
        $loggedOut = $this->checks();
        $this->assertFalse($loggedOut['gh auth status']['ok']);
        $this->assertStringContainsString('not logged in', $loggedOut['gh auth status']['detail']);

        Config::fakeGhBinary($this->dir . '/missing-gh');
        $this->fakeShell(fn (array $cmd): ?array => $this->missingWhich($cmd, ['missing-gh']));
        $noGh = $this->checks();
        $this->assertFalse($noGh['gh CLI']['ok']);
        $this->assertStringContainsString('not found', $noGh['gh CLI']['detail']);
        Config::fakeGhBinary(null);
    }

    public function testConfiguredScopeRepoAppAndApiFailure(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_IP_ALLOWLIST=127.0.0.1/32');
        GithubClient::fakeFetch(static fn (): array => []);
        $this->fakeShell();
        $org = $this->checks();
        $this->assertSame('org (acme)', $org['scope']['detail']);
        $this->assertTrue($org['GitHub API access']['ok']);
        $this->assertSame('logged in', $org['gh auth status']['detail']);
        $this->assertTrue($org['ok']);

        putenv('RUNNERDECK_SCOPE=repo');
        putenv('RUNNERDECK_REPO=acme/app');
        $repo = $this->checks();
        $this->assertSame('repo (acme/app)', $repo['scope']['detail']);

        $this->fakeShell(fn (array $cmd): ?array => $this->authStatus($cmd, 1, 'logged out', ''));
        $loggedOut = $this->checks();
        $this->assertFalse($loggedOut['gh auth status']['ok']);
        $this->assertStringContainsString('gh auth login', $loggedOut['gh auth status']['detail']);

        $this->installApp();
        GithubApp::fakeToken(static fn (): ?string => null);
        $appDown = $this->checks();
        $this->assertFalse($appDown['gh auth status']['ok']);
        $this->assertStringContainsString('private key', $appDown['gh auth status']['detail']);

        GithubApp::fakeToken(static fn (): ?string => 'ghs_test');
        $appUp = $this->checks();
        $this->assertSame('GitHub App installation token', $appUp['gh auth status']['detail']);

        GithubClient::fakeFetch(static function (): array {
            throw new RuntimeException('nope');
        });
        $api = $this->checks();
        $this->assertFalse($api['GitHub API access']['ok']);
        $this->assertStringContainsString('nope', $api['GitHub API access']['detail']);
    }

    public function testHostingPressureDiskAndPoolEdges(): void
    {
        putenv('RUNNERDECK_BIND_HOST=0.0.0.0');
        putenv('RUNNERDECK_IP_ALLOWLIST=10.0.0.0/8');
        $gated = $this->checks();
        $this->assertStringContainsString('IP allowlist', $gated['bind']['detail']);
        $this->assertFalse($gated['TOTP']['ok']);

        putenv('RUNNERDECK_IP_ALLOWLIST');
        $open = $this->checks();
        $this->assertStringContainsString('neither IP allowlist', $open['bind']['detail']);

        putenv('RUNNERDECK_BIND_HOST=::1');
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=abc');
        putenv('RUNNERDECK_TRUST_PROXY=1');
        $loop = $this->checks();
        $this->assertStringContainsString('loopback', $loop['bind']['detail']);
        $this->assertSame('enabled', $loop['TOTP']['detail']);
        $this->assertTrue($loop['TRUST_PROXY']['ok']);
        $this->assertStringContainsString('forge', $loop['TRUST_PROXY']['detail']);

        HostPressure::fakeDisk(static fn (): array => [null, null]);
        HostPressure::fakeRam(static fn (): ?int => null);
        $unknown = $this->checks();
        $this->assertStringContainsString('disk unknown', $unknown['headroom']['detail']);
        $this->assertStringContainsString('RAM unknown', $unknown['headroom']['detail']);
        $this->assertTrue($unknown['slot disk']['ok']);
        $this->assertStringContainsString('unknown', $unknown['slot disk']['detail']);

        HostPressure::fakeDisk(static fn (): array => [1024, 1024]);
        HostPressure::fakeRam(static fn (): int => 1024);
        $tight = $this->checks();
        $this->assertStringContainsString('starts will refuse', $tight['headroom']['detail']);
        $this->assertFalse($tight['slot disk']['ok']);

        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/later');
        $this->fakeShell();
        $later = $this->checks();
        $this->assertStringContainsString('will be created', $later['pool directory']['detail']);

        $pkg = $this->dir . '/pkg';
        mkdir($pkg . '/my pool', 0755, true);
        file_put_contents($pkg . '/package.json', '{}');
        putenv('RUNNERDECK_POOL_DIR=' . $pkg . '/my pool');
        [, $blockedJson] = $this->doctor(['doctor', '--json']);
        $blocked = json_decode($blockedJson, true);
        $poolDetails = [];
        foreach ($blocked['checks'] as $check) {
            if ($check['name'] === 'pool directory') {
                $poolDetails[] = $check['detail'];
            }
        }
        $joined = implode("\n", $poolDetails);
        $this->assertStringContainsString('whitespace', $joined);
        $this->assertStringContainsString('package.json', $joined);

        mkdir($this->dir . '/pool/runner-1/bin', 0755, true);
        mkdir($this->dir . '/pool/runner-2/bin', 0755, true);
        file_put_contents($this->dir . '/pool/runner-1/bin/a', 'one');
        file_put_contents($this->dir . '/pool/runner-2/bin/b', 'two');
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        HostPressure::fakeDisk(static fn (): array => [20 * 1024 * 1024 * 1024, 40 * 1024 * 1024 * 1024]);
        HostPressure::fakeRam(static fn (): int => 8 * 1024 * 1024);
        $dup = $this->checks();
        $this->assertTrue($dup['agent files']['ok']);

        // A file cannot be the parent of the storage directory, including for root,
        // which can create /no/such and then reports that directory as writable.
        $blocked = $this->dir . '/not-a-directory';
        file_put_contents($blocked, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocked . '/nested/settings.json');
        $storage = $this->checks();
        $this->assertFalse($storage['storage directory']['ok']);
    }

    public function testExtractShimLibrariesAndTextSummaries(): void
    {
        $this->fakeShell(function (array $cmd): ?array {
            if (($cmd[0] ?? '') === 'tar' && in_array('-xf', $cmd, true)) {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'Function not implemented'];
            }
            return null;
        });
        $shim = $this->checks();
        $this->assertTrue($shim['package extract']['ok']);
        $this->assertStringContainsString('bsdtar', $shim['package extract']['detail']);

        Provisioner::fakeHost(false, false);
        $this->fakeShell(static fn (array $cmd): ?array => ['code' => 1, 'stdout' => '', 'stderr' => '']);
        $libs = $this->checks();
        // macOS and Alpine skip this check; Doctor only emits it on other Linux.
        if (isset($libs['runner OS libraries'])) {
            $this->assertTrue($libs['runner OS libraries']['ok']);
            $this->assertStringContainsString('libicu', $libs['runner OS libraries']['detail']);
        }
        Provisioner::fakeHost(null, null);

        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_IP_ALLOWLIST=127.0.0.1/32');
        GithubClient::fakeFetch(static fn (): array => []);
        $this->fakeShell();
        // PHP 8.1 always warns that it is end of life. Pin a patched version
        // so the clean summary does not depend on the job's PHP.
        Doctor::fakePhpVersion('8.5.13');
        $clean = $this->render(false, true);
        $this->assertSame(0, $clean[0]);
        $this->assertStringContainsString('All checks passed.', $clean[1]);
        $this->assertStringContainsString("\033[32m", $clean[1]);

        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_IP_ALLOWLIST');
        $warned = $this->render(false, false);
        $this->assertSame(0, $warned[0]);
        $this->assertStringContainsString('All required checks passed', $warned[1]);
        $this->assertStringNotContainsString("\033[", $warned[1]);

        putenv('RUNNERDECK_POOL_DIR=/no/such/runnerdeck-doctor-pool/runners');
        $failed = $this->render(false, true);
        $this->assertSame(1, $failed[0]);
        $this->assertStringContainsString('check(s) failed', $failed[1]);
        $this->assertStringContainsString("\033[31m", $failed[1]);

        putenv('RUNNERDECK_NO_BANNER=1');
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        Doctor::fakeBanner(true);
        $banner = $this->render(false, false);
        $this->assertStringContainsString('RunnerDeck doctor', $banner[1]);
    }

    /** @return array<string, array{name: string, ok: bool, detail: string}>&array{ok: bool} */
    private function checks(): array
    {
        [, $json] = $this->doctor(['doctor', '--json']);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $indexed = ['ok' => $data['ok']];
        foreach ($data['checks'] as $check) {
            $indexed[$check['name']] = $check;
        }
        return $indexed;
    }

    /** @return array{0: int, 1: string} */
    private function render(bool $json, bool $color): array
    {
        $out = fopen('php://memory', 'w+');
        $this->assertIsResource($out);
        $code = (new Doctor($json, $color, $out))->run();
        rewind($out);
        return [$code, (string) stream_get_contents($out)];
    }

    /** @param null|callable(list<string>): ?array{code: int, stdout: string, stderr: string} $decide */
    private function fakeShell(?callable $decide = null): void
    {
        Shell::fake(function (array $cmd) use ($decide): array {
            if ($decide !== null) {
                $custom = $decide($cmd);
                if ($custom !== null) {
                    return $custom;
                }
            }
            return $this->commandResult($cmd);
        });
    }

    /**
     * @param list<string> $cmd
     * @param list<string> $names
     * @return array{code: int, stdout: string, stderr: string}|null
     */
    private function missingWhich(array $cmd, array $names): ?array
    {
        $tool = $cmd[0] ?? '';
        $arg = $cmd[1] ?? '';
        if ($tool !== 'which' && $tool !== 'where') {
            return null;
        }
        foreach ($names as $name) {
            if ($arg === $name || str_ends_with($arg, '/' . $name)) {
                return ['code' => 1, 'stdout' => '', 'stderr' => ''];
            }
        }
        return null;
    }

    /**
     * @param list<string> $cmd
     * @return array{code: int, stdout: string, stderr: string}|null
     */
    private function authStatus(array $cmd, int $code, string $stdout, string $stderr): ?array
    {
        if (in_array('auth', $cmd, true) && in_array('status', $cmd, true)) {
            return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
        }
        return null;
    }

    /**
     * @param list<string> $cmd
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function commandResult(array $cmd): array
    {
        $flag = array_search('-cf', $cmd, true);
        if ($flag !== false) {
            file_put_contents($cmd[$flag + 1], 'tar');
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        }
        $dest = array_search('-C', $cmd, true);
        if ($dest !== false && in_array('-xf', $cmd, true)) {
            $dir = $cmd[$dest + 1];
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($dir . '/payload.txt', 'ok');
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        }
        $name = $cmd[1] ?? 'tool';
        if (($cmd[0] ?? '') === 'which' || ($cmd[0] ?? '') === 'where') {
            return ['code' => 0, 'stdout' => '/usr/bin/' . $name . "\n", 'stderr' => ''];
        }
        return ['code' => 0, 'stdout' => "1\n", 'stderr' => ''];
    }

    private function installApp(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n";
        file_put_contents($this->dir . '/app.pem', $pem);
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE=' . $this->dir . '/app.pem');
        putenv('RUNNERDECK_GITHUB_APP_ID=1');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID=2');
    }

    /** @return list<string> */
    private function expectedTextCheckNames(): array
    {
        $names = ['PHP version'];
        if (!Platform::isWindows()) {
            $names[] = 'ext-posix';
            $names[] = 'ext-pcntl';
        }
        $names[] = 'ext-pdo_sqlite';
        if (Platform::isWindows()) {
            $names[] = 'powershell.exe';
            $names[] = 'taskkill';
        } else {
            $names[] = 'bash';
        }
        array_push(
            $names,
            'curl',
            'tar',
            'gh CLI',
            'gh auth status',
            'scope',
            'bind',
            'extra gate',
            'TOTP',
            'TRUST_PROXY',
            'headroom',
            'pool directory',
            'slot disk',
            'package extract'
        );
        if (PHP_OS_FAMILY === 'Linux' && !is_file('/etc/alpine-release')) {
            $names[] = 'runner OS libraries';
        }
        $names[] = 'storage directory';

        return $names;
    }

    /** @return list<string> */
    private function textCheckNames(string $plain): array
    {
        preg_match_all('/\[(?: OK |WARN|FAIL)\] ([^—\r\n]+)/', $plain, $matches);

        return array_map(static fn (string $name): string => trim($name), $matches[1]);
    }

    /** @param list<string> $argv @return array{0: int, 1: string} */
    private function doctor(array $argv): array
    {
        $out = fopen('php://memory', 'w+');
        $this->assertIsResource($out);
        $code = Doctor::fromArgv($argv, $out)->run();
        rewind($out);
        return [$code, (string) stream_get_contents($out)];
    }
}
