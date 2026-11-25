<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

use RunnerDeck\Configuration\Config;
use RunnerDeck\Configuration\Settings;
use RunnerDeck\GitHub\GithubApp;
use RunnerDeck\GitHub\GithubAuthStatus;
use RunnerDeck\GitHub\GithubClient;
use RunnerDeck\Host\HostPressure;
use RunnerDeck\Host\SlotDisk;
use RunnerDeck\Pool\PoolLayout;
use RunnerDeck\Pool\Provisioner;
use RunnerDeck\Presentation\Banner;
use RunnerDeck\Security\HostingWarnings;
use RunnerDeck\System\Platform;
use RunnerDeck\System\Shell;

/**
 * Machine checks for `runnerdeck doctor`. Text output stays as it was.
 * `--json` prints `{ok, checks:[{name, ok, detail}]}` instead, including
 * when `--quiet` is also set. A warning is ok with its text in detail.
 */
final class Doctor
{
    /** @var list<array{name: string, ok: bool, detail: string}> */
    private array $checks = [];

    private ?GithubAuthStatus $auth = null;

    /**
     * @var array{
     *     ok: bool,
     *     reasons: list<string>,
     *     disk_free_kb: ?int,
     *     disk_total_kb: ?int,
     *     ram_available_kb: ?int
     * }
     */
    private array $pressure = [
        'ok' => true,
        'reasons' => [],
        'disk_free_kb' => null,
        'disk_total_kb' => null,
        'ram_available_kb' => null,
    ];

    private int $failures = 0;

    private int $warnings = 0;

    /** @var resource */
    private $out;

    /**
     * @param resource|null $out
     */
    public function __construct(private bool $json, private bool $color, $out = null)
    {
        $this->out = $out ?? STDOUT;
    }

    /**
     * @param list<string> $argv
     * @param resource|null $out
     */
    public static function fromArgv(array $argv, $out = null): self
    {
        $json = in_array('--json', $argv, true);
        $color = !$json && function_exists('stream_isatty') && stream_isatty(STDOUT);
        return new self($json, $color, $out);
    }

    public function run(): int
    {
        if (!$this->json) {
            if ($this->out === STDOUT) {
                Banner::show();
            }
            $this->write("RunnerDeck doctor\n");
        }

        $this->phpChecks();
        $this->toolChecks();
        $this->configurationChecks();
        $this->hostingChecks();
        $this->pressureChecks();
        $this->filesystemChecks();

        if ($this->json) {
            $encoded = json_encode(
                ['ok' => $this->failures === 0, 'checks' => $this->checks],
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
            $this->write($encoded . "\n");
            return $this->failures > 0 ? 1 : 0;
        }

        $this->write("\n");
        if ($this->failures > 0) {
            $summary = "{$this->failures} check(s) failed";
            $extra = $this->warnings > 0 ? ", {$this->warnings} warning(s)" : '';
            $this->write($this->paint('31', $summary) . $extra . ".\n");
            return 1;
        }
        if ($this->warnings > 0) {
            $this->write($this->paint('33', "All required checks passed, {$this->warnings} warning(s).") . "\n");
            return 0;
        }
        $this->write($this->paint('32', 'All checks passed.') . "\n");
        return 0;
    }

    private function phpChecks(): void
    {
        $this->section('PHP');
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $this->failures += $this->fail('PHP version', PHP_VERSION . ' — RunnerDeck needs 8.1 or newer');
        } else {
            $this->ok('PHP version', PHP_VERSION);
            $patched = [
                '8.2' => '8.2.35',
                '8.3' => '8.3.36',
                '8.4' => '8.4.27',
                '8.5' => '8.5.12',
            ];
            $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            if (PHP_VERSION_ID < 80200) {
                $this->warnings += $this->warn(
                    'PHP security',
                    PHP_VERSION . ' is end-of-life — no official fix for'
                        . ' CVE-2026-109618 / CVE-2026-109619; use a distro backport or move to 8.2.35+'
                );
            } elseif (isset($patched[$branch]) && version_compare(PHP_VERSION, $patched[$branch], '<')) {
                $this->warnings += $this->warn(
                    'PHP security',
                    PHP_VERSION . ' is below ' . $patched[$branch]
                        . ' (CVE-2026-109618 / CVE-2026-109619). Update the host PHP packages'
                        . ' and restart php-fpm / run.sh'
                );
            }
        }
        foreach (Platform::isWindows() ? [] : ['posix', 'pcntl'] as $ext) {
            if (extension_loaded($ext)) {
                $this->ok("ext-{$ext}", '');
            } else {
                $this->failures += $this->fail(
                    "ext-{$ext}",
                    'required for process liveness checks and stopping runners'
                );
            }
        }
        if (extension_loaded('pdo_sqlite')) {
            $this->ok('ext-pdo_sqlite', '');
        } else {
            $this->warnings += $this->warn(
                'ext-pdo_sqlite',
                'optional — the CPU/RAM history chart stays empty without it'
            );
        }
    }

    private function toolChecks(): void
    {
        $this->section('External tools');
        if (Platform::isWindows()) {
            foreach (['powershell.exe', 'taskkill'] as $tool) {
                if (Platform::commandExists($tool)) {
                    $this->ok($tool, '');
                } else {
                    $this->failures += $this->fail(
                        $tool,
                        'needed to start, stop and inspect runner processes on Windows'
                    );
                }
            }
        } elseif (Platform::commandExists('bash')) {
            $this->ok('bash', '');
        } else {
            $this->failures += $this->fail(
                'bash',
                'run.sh needs bash — Alpine and some minimal distros don\'t ship it by default'
            );
        }
        if (Platform::commandExists('curl')) {
            $this->ok('curl', '');
        } else {
            $this->failures += $this->fail('curl', 'needed to download the runner package on first start');
        }
        if (Platform::commandExists('tar')) {
            $this->ok('tar', '');
        } else {
            $this->failures += $this->fail('tar', 'needed to extract the downloaded runner package');
        }

        $gh = Config::ghBinary();
        if (is_executable($gh) || Platform::commandExists($gh)) {
            $this->ok('gh CLI', $gh);
            if (Config::isConfigured()) {
                $auth = GithubClient::authStatus();
                if ($auth->loggedIn) {
                    $this->ok(
                        'gh auth status',
                        GithubApp::isConfigured() ? 'GitHub App installation token' : 'logged in'
                    );
                } else {
                    $hint = GithubApp::isConfigured()
                        ? 'check GitHub App ID, installation, and private key in Settings'
                        : 'run `gh auth login` or set a GitHub App in Settings';
                    $this->failures += $this->fail('gh auth status', $auth->message . ' — ' . $hint);
                }
                $this->auth = $auth;
            } else {
                $login = Shell::exec([$gh, 'auth', 'status'], 10);
                if ($login['code'] === 0) {
                    $this->ok('gh auth status', 'logged in');
                } else {
                    $detail = trim($login['stderr'] ?: $login['stdout']) . ' — run `gh auth login`';
                    $this->failures += $this->fail('gh auth status', $detail);
                }
            }
        } else {
            $this->failures += $this->fail('gh CLI', 'not found — install from https://cli.github.com/');
        }
    }

    private function configurationChecks(): void
    {
        $this->section('Configuration');
        if (!Config::isConfigured()) {
            $this->warnings += $this->warn(
                'scope',
                'not configured yet — open the app and use the first-run setup screen,'
                    . ' or set RUNNERDECK_ORG/RUNNERDECK_REPO'
            );
        } else {
            $scope = Config::scope();
            $identifier = $scope === 'repo' ? Config::repo() : Config::org();
            $this->ok('scope', "{$scope} ({$identifier})");
            if ($this->auth !== null && $this->auth->loggedIn) {
                if ($this->auth->orgAccessOk) {
                    $this->ok('GitHub API access', "can read {$scope} runners");
                } else {
                    $this->failures += $this->fail('GitHub API access', $this->auth->message);
                }
            }
        }
    }

    private function hostingChecks(): void
    {
        $this->section('Hosting');
        $bind = getenv('RUNNERDECK_BIND_HOST') ?: '127.0.0.1';
        if ($bind === '127.0.0.1' || $bind === '::1') {
            $this->ok('bind', $bind . ' (loopback)');
            if (Config::ipAllowlistCidrs() === [] && !Config::requireMtls()) {
                $this->warnings += $this->warn('extra gate', HostingWarnings::proxyGapText());
            }
        } else {
            $extra = Config::ipAllowlistCidrs() !== [] || Config::requireMtls()
                ? 'IP allowlist or mTLS is set'
                : 'neither IP allowlist nor mTLS — PHP will 403 public Host (docs/hosting.md)';
            $this->warnings += $this->warn(
                'bind',
                "{$bind} — TOTP required; put TLS in front; {$extra}"
            );
        }
        if (Config::authEnabled()) {
            $this->ok('TOTP', 'enabled');
        } elseif ($bind === '127.0.0.1' || $bind === '::1') {
            $this->ok('TOTP', 'off (loopback only)');
        } else {
            $this->failures += $this->fail('TOTP', 'non-loopback bind requires php app/bin/setup-totp.php');
        }
        if (Config::trustProxy()) {
            $this->warnings += $this->warn(
                'TRUST_PROXY',
                'on — anyone who can reach PHP can forge X-Forwarded-Proto; keep PHP on loopback'
            );
        } else {
            $this->ok('TRUST_PROXY', 'off');
        }
    }

    private function pressureChecks(): void
    {
        $this->section('Host pressure');
        $pressure = HostPressure::snapshot();
        $this->pressure = $pressure;
        if ($pressure['ok']) {
            $disk = $pressure['disk_free_kb'] !== null
                ? SlotDisk::formatKb($pressure['disk_free_kb']) . ' disk free'
                : 'disk unknown';
            $ram = $pressure['ram_available_kb'] !== null
                ? SlotDisk::formatKb($pressure['ram_available_kb']) . ' RAM available'
                : 'RAM unknown';
            $this->ok('headroom', $disk . ', ' . $ram);
        } else {
            $this->warnings += $this->warn(
                'headroom',
                implode('; ', $pressure['reasons']) . ' — starts will refuse'
            );
        }
    }

    private function filesystemChecks(): void
    {
        $this->section('Filesystem');
        $poolDir = Config::poolDir();
        if (is_dir($poolDir) && is_writable($poolDir)) {
            $this->ok('pool directory', $poolDir);
        } elseif (!is_dir($poolDir) && is_writable(dirname($poolDir))) {
            $this->ok('pool directory', "{$poolDir} (will be created on first start)");
        } else {
            $this->failures += $this->fail('pool directory', "{$poolDir} is not writable");
        }
        $whitespace = PoolLayout::whitespaceMessage($poolDir);
        if ($whitespace !== null) {
            $this->failures += $this->fail('pool directory', $whitespace);
        }
        $packageJson = PoolLayout::packageJsonBlockMessage($poolDir);
        if ($packageJson !== null) {
            $this->failures += $this->fail('pool directory', $packageJson);
        }

        $slotCount = PoolLayout::slotCount();
        $free = $this->pressure['disk_free_kb'];
        $space = PoolLayout::spaceSentence($slotCount, $free);
        $needKiB = PoolLayout::estimatedKiB(max(1, $slotCount));
        if ($free === null) {
            $this->warnings += $this->warn('slot disk', $space);
        } elseif ($free < $needKiB) {
            $this->failures += $this->fail('slot disk', $space . ' — Start all would fill the disk');
        } else {
            $this->ok('slot disk', $space);
        }
        if (PoolLayout::agentTreesDuplicated()) {
            $this->warnings += $this->warn(
                'agent files',
                'each slot has its own bin or externals tree — the next start hardlinks them onto one copy'
            );
        }

        $probeRoot = null;
        if (is_dir($poolDir) && is_writable($poolDir)) {
            $probeRoot = $poolDir;
        } elseif (!is_dir($poolDir) && is_writable(dirname($poolDir))) {
            $probeRoot = dirname($poolDir);
        }
        if ($probeRoot !== null) {
            $probe = Provisioner::probeExtract($probeRoot);
            if (!$probe['ok']) {
                $this->failures += $this->fail('package extract', $probe['message']);
            } elseif ($probe['shim']) {
                $this->warnings += $this->warn('package extract', $probe['message']);
            } else {
                $this->ok('package extract', $probe['message']);
            }
        }

        if (PHP_OS_FAMILY === 'Linux' && !is_file('/etc/alpine-release')) {
            $libs = Provisioner::osLibrariesStatus($poolDir);
            if ($libs['ok']) {
                $this->ok('runner OS libraries', $libs['detail']);
            } else {
                $this->warnings += $this->warn('runner OS libraries', $libs['detail']);
            }
        }

        $storageDir = Settings::storageDir();
        $storageOk = (is_dir($storageDir) && is_writable($storageDir))
            || (!is_dir($storageDir) && is_writable(dirname($storageDir)));
        if ($storageOk) {
            $this->ok('storage directory', $storageDir);
        } else {
            $this->failures += $this->fail(
                'storage directory',
                "{$storageDir} is not writable — settings and history won't save"
            );
        }
    }

    private function section(string $title): void
    {
        if (!$this->json) {
            $this->write("\n{$title}\n");
        }
    }

    private function ok(string $label, string $detail): void
    {
        $this->checks[] = ['name' => $label, 'ok' => true, 'detail' => $detail];
        if ($this->json) {
            return;
        }
        $suffix = $detail !== '' ? " — {$detail}" : '';
        $this->write('  ' . $this->paint('32', '[ OK ]') . " {$label}{$suffix}\n");
    }

    private function warn(string $label, string $detail): int
    {
        $this->checks[] = ['name' => $label, 'ok' => true, 'detail' => $detail];
        if (!$this->json) {
            $this->write('  ' . $this->paint('33', '[WARN]') . " {$label} — {$detail}\n");
        }
        return 1;
    }

    private function fail(string $label, string $detail): int
    {
        $this->checks[] = ['name' => $label, 'ok' => false, 'detail' => $detail];
        if (!$this->json) {
            $this->write('  ' . $this->paint('31', '[FAIL]') . " {$label} — {$detail}\n");
        }
        return 1;
    }

    private function paint(string $code, string $text): string
    {
        return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    private function write(string $text): void
    {
        fwrite($this->out, $text);
    }
}
