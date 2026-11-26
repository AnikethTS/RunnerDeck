<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

use RunnerDeck\Configuration\Config;
use RunnerDeck\GitHub\UpdateCheck;
use RunnerDeck\System\Shell;

final class Updater
{
    private const REPO = 'AnikethTS/RunnerDeck';
    private const METHODS = ['brew', 'deb', 'windows', 'git', 'zip', 'docker', 'unknown'];
    /** Kept when a release zip is copied over an install.sh folder. */
    private const ZIP_KEEP = ['.env', 'storage', 'runners'];

    /** @var resource */
    private $out;
    /** @var resource */
    private $err;
    /** @var resource */
    private $in;
    /** @var callable(list<string>): int */
    private $interactive;

    /**
     * @param resource $out
     * @param resource $err
     * @param resource $in
     * @param null|callable(list<string>): int $interactive
     */
    public function __construct($out, $err, $in, ?callable $interactive = null)
    {
        $this->out = $out;
        $this->err = $err;
        $this->in = $in;
        $this->interactive = $interactive ?? self::onTerminal(...);
    }

    /**
     * @param array{windows: bool, docker: bool, git: bool} $facts
     */
    public static function detectMethod(string $root, array $facts, ?string $override = null): string
    {
        if ($override !== null && in_array($override, self::METHODS, true)) {
            return $override;
        }
        $path = str_replace('\\', '/', $root);
        return match (true) {
            $facts['windows'] => 'windows',
            $facts['docker'] => 'docker',
            str_contains($path, '/Cellar/') || str_contains($path, '/linuxbrew/') => 'brew',
            str_starts_with($path, '/usr/share/runnerdeck') => 'deb',
            $facts['git'] => 'git',
            self::isZipInstall($path) => 'zip',
            default => 'unknown',
        };
    }

    /** @return array{windows: bool, docker: bool, git: bool} */
    public static function factsFor(string $root): array
    {
        return [
            'windows' => PHP_OS_FAMILY === 'Windows',
            'docker' => is_file('/.dockerenv'),
            'git' => is_dir($root . '/.git'),
        ];
    }

    public static function checksumMatches(string $file, string $sums): bool
    {
        $name = basename($file);
        foreach (preg_split('/\R/', $sums) ?: [] as $line) {
            if (preg_match('/^([0-9a-fA-F]{64})\s+\*?(.+)$/', trim($line), $m) === 1 && $m[2] === $name) {
                $actual = is_file($file) ? hash_file('sha256', $file) : false;
                return $actual !== false && hash_equals(strtolower($m[1]), $actual);
            }
        }
        return false;
    }

    /** @return array{asset: string, sums: string}|null */
    public static function assetsFor(string $method, string $version): ?array
    {
        return match ($method) {
            'deb' => ['asset' => "RunnerDeck-{$version}-linux-all.deb", 'sums' => 'SHA256SUMS-linux-all'],
            'windows' => [
                'asset' => "RunnerDeck-{$version}-windows-x64-setup.exe",
                'sums' => 'SHA256SUMS-windows-x64',
            ],
            'zip' => [
                'asset' => "runnerdeck-v{$version}.zip",
                'sums' => "runnerdeck-v{$version}.zip.sha256",
            ],
            default => null,
        };
    }

    public function run(bool $checkOnly, bool $assumeYes, bool $json, string $root, ?string $method = null): int
    {
        $info = UpdateCheck::check();
        $method ??= self::detectMethod($root, self::factsFor($root), getenv('RUNNERDECK_INSTALL_METHOD') ?: null);
        $info['method'] = $method;

        if ($info['error'] !== null) {
            return $this->finish($json, $info + ['ok' => false], "could not check for updates: {$info['error']}", 1);
        }
        if (!$info['update_available']) {
            return $this->finish($json, $info + ['ok' => true], "RunnerDeck {$info['current']} is up to date.", 0);
        }
        $latest = (string) $info['latest'];
        $headline = "RunnerDeck {$latest} is available (you have {$info['current']}).";
        if ($checkOnly) {
            return $this->finish($json, $info + ['ok' => true], $headline . ' Run: runnerdeck update', 0);
        }
        if ($json) {
            return $this->finish(true, $info + ['ok' => false], '', 1, 'pass --yes to update without a prompt');
        }

        $this->say($headline);
        if (in_array($method, ['docker', 'unknown'], true)) {
            $this->say($this->manualAdvice($method, $latest));
            return 0;
        }
        $this->say('Install method: ' . $method . '. ' . $this->describe($method, $latest));
        if (!$assumeYes && !$this->confirm('Update now? [y/N] ')) {
            $this->say('Not updated.');
            return 0;
        }
        $code = $this->apply($method, $latest, $root);
        if ($code === 0) {
            $this->say("Updated to RunnerDeck {$latest}. Restart the dashboard to use it.");
        }
        return $code;
    }

    private function describe(string $method, string $latest): string
    {
        return match ($method) {
            'brew' => 'Runs: brew upgrade anikethts/runnerdeck/runnerdeck',
            'deb' => "Downloads and checks RunnerDeck-{$latest}-linux-all.deb, then installs it with sudo apt.",
            'windows' => 'Downloads and checks the Setup wizard, then opens it.',
            'zip' => 'Downloads and checks the release zip, then copies it over this folder.'
                . ' Settings, runners, and .env stay.',
            default => 'Runs: git pull --ff-only',
        };
    }

    private function manualAdvice(string $method, string $latest): string
    {
        return $method === 'docker'
            ? "Pull the new image and recreate the container: docker compose pull && docker compose up -d\n"
                . "Release notes: https://github.com/" . self::REPO . "/releases/tag/v{$latest}"
            : "Could not tell how this copy was installed. Download v{$latest} from\n"
                . "https://github.com/" . self::REPO . "/releases/latest, or set RUNNERDECK_INSTALL_METHOD\n"
                . 'to one of: brew, deb, windows, git, zip.';
    }

    private function apply(string $method, string $latest, string $root): int
    {
        if ($method === 'brew') {
            return $this->tty(['brew', 'upgrade', 'anikethts/runnerdeck/runnerdeck']);
        }
        if ($method === 'git') {
            return $this->tty(['git', '-C', $root, 'pull', '--ff-only']);
        }
        if ($method === 'zip') {
            return $this->applyZip($latest, $root);
        }
        $assets = self::assetsFor($method, $latest);
        if ($assets === null) {
            return $this->problem("cannot update an install of type {$method}");
        }
        $dir = sys_get_temp_dir() . '/runnerdeck-update-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0755, true);
        try {
            $file = $this->download($dir, $latest, $assets);
            if ($file === null) {
                return 1;
            }
            return $method === 'deb'
                ? $this->tty(['sudo', 'apt-get', 'install', '-y', $file])
                : $this->tty(self::windowsSetupCommand($file));
        } finally {
            $this->removeDir($dir);
        }
    }

    private function applyZip(string $latest, string $root): int
    {
        $dir = sys_get_temp_dir() . '/runnerdeck-update-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0755, true);
        try {
            $file = $this->download($dir, $latest, [
                'asset' => "runnerdeck-v{$latest}.zip",
                'sums' => "runnerdeck-v{$latest}.zip.sha256",
            ]);
            if ($file === null) {
                return 1;
            }
            $unpacked = $dir . '/unpacked';
            if (!@mkdir($unpacked, 0755, true) && !is_dir($unpacked)) {
                return $this->problem('could not unpack the release');
            }
            $got = Shell::exec(['unzip', '-q', $file, '-d', $unpacked], 120);
            if ($got['code'] !== 0) {
                return $this->problem('unzip failed: ' . (trim($got['stderr']) ?: 'unzip failed'));
            }
            $src = $unpacked . '/runnerdeck-v' . $latest;
            if (!is_file($src . '/runnerdeck')) {
                return $this->problem('unexpected archive layout; nothing was installed');
            }
            if (!self::copyOver($src, $root)) {
                return $this->problem('could not copy the new release into ' . $root);
            }
            @chmod($root . '/runnerdeck', 0755);

            return $this->tty([$root . '/runnerdeck', 'install', '--force']);
        } finally {
            $this->removeDir($dir);
        }
    }

    /** Copy the release onto $dest. Top-level .env, storage, and runners are left alone. */
    public static function copyOver(string $src, string $dest): bool
    {
        if (!is_dir($src)) {
            return false;
        }
        if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
            return false;
        }
        $names = @scandir($src);
        if ($names === false) {
            return false;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || in_array($name, self::ZIP_KEEP, true)) {
                continue;
            }
            $from = $src . '/' . $name;
            $to = $dest . '/' . $name;
            if (is_dir($from) && !is_link($from)) {
                if (!self::copyOver($from, $to)) {
                    return false;
                }
                continue;
            }
            if ((is_file($to) || is_link($to)) && !@unlink($to)) {
                return false;
            }
            if (!@copy($from, $to)) {
                return false;
            }
        }

        return true;
    }

    private static function isZipInstall(string $path): bool
    {
        $home = rtrim(str_replace('\\', '/', (string) getenv('HOME')), '/');
        if ($home === '') {
            return false;
        }

        return rtrim($path, '/') === $home . '/.local/share/runnerdeck';
    }

    /** @param array{asset: string, sums: string} $assets */
    private function download(string $dir, string $latest, array $assets): ?string
    {
        $this->say("Downloading {$assets['asset']}...");
        $got = Shell::exec([
            Config::ghBinary(), 'release', 'download', "v{$latest}", '--repo', self::REPO,
            '--pattern', $assets['asset'], '--pattern', $assets['sums'], '--dir', $dir,
        ], 600);
        if ($got['code'] !== 0) {
            $this->problem('download failed: ' . (trim($got['stderr']) ?: 'gh release download failed'));
            return null;
        }
        $file = $dir . '/' . $assets['asset'];
        $sums = is_file($dir . '/' . $assets['sums']) ? (string) file_get_contents($dir . '/' . $assets['sums']) : '';
        if (!self::checksumMatches($file, $sums)) {
            $this->problem("{$assets['asset']} does not match {$assets['sums']}; nothing was installed");
            return null;
        }
        $this->say('Checksum matches.');
        return $file;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Runs the Setup wizard and waits until it exits. The wizard is the
     * command itself: cmd.exe /c start mis-parses the quoted arguments and
     * looks for a program named "start". RUNNERDECK_UPDATE_SILENT=1 adds the
     * Inno Setup switches that skip every prompt. RUNNERDECK_UPDATE_DIR, when
     * it is a plain path, is that wizard's /DIR.
     *
     * @return list<string>
     */
    public static function windowsSetupCommand(string $file): array
    {
        $cmd = [$file];
        if (getenv('RUNNERDECK_UPDATE_SILENT') !== '1') {
            return $cmd;
        }
        $cmd[] = '/VERYSILENT';
        $cmd[] = '/SUPPRESSMSGBOXES';
        $cmd[] = '/NORESTART';
        $cmd[] = '/TASKS=';
        $dir = getenv('RUNNERDECK_UPDATE_DIR');
        if (is_string($dir) && $dir !== '' && preg_match('/["\r\n&|<>]/', $dir) !== 1) {
            $cmd[] = '/DIR=' . $dir;
        }

        return $cmd;
    }

    /** @param list<string> $cmd */
    private function tty(array $cmd): int
    {
        $code = ($this->interactive)($cmd);
        if ($code !== 0) {
            $this->problem(basename($cmd[0]) . " exited with {$code}");
        }
        return $code === 0 ? 0 : 1;
    }

    /** @param list<string> $cmd */
    public static function onTerminal(array $cmd): int
    {
        $proc = @proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        return is_resource($proc) ? proc_close($proc) : -1;
    }

    private function confirm(string $question): bool
    {
        fwrite($this->out, $question);
        $answer = fgets($this->in);
        return $answer !== false && in_array(strtolower(trim($answer)), ['y', 'yes'], true);
    }

    /** @param array<string, mixed> $info */
    private function finish(bool $json, array $info, string $text, int $code, ?string $why = null): int
    {
        if ($json) {
            if ($why !== null) {
                $info['message'] = $why;
            }
            fwrite($this->out, json_encode($info) . "\n");
        } elseif ($code === 0) {
            $this->say($text);
        } else {
            $this->problem($text);
        }
        return $code;
    }

    private function say(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    private function problem(string $text): int
    {
        fwrite($this->err, "runnerdeck: {$text}\n");
        return 1;
    }
}
