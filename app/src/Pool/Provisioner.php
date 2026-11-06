<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use RunnerDeck\Logging\AppLog;
use RunnerDeck\Configuration\Config;
use RunnerDeck\System\Platform;
use RunnerDeck\System\Shell;
use RunnerDeck\GitHub\AgentRoster;
use RunnerDeck\GitHub\GithubClient;

final class Provisioner
{
    public static function ensureInstalled(string $dir, bool $replace = false): array
    {
        if (!$replace && self::binariesPresent($dir)) {
            return ['ok' => true, 'message' => 'runner binaries already present'];
        }

        try {
            $download = self::pickDownload();
            $pin = Config::agentSha();
            $listed = $download['sha256_checksum'] ?? null;
            if (
                $pin !== null
                && is_string($listed)
                && $listed !== ''
                && strtolower($listed) !== $pin
            ) {
                return self::fail(
                    'provision.pin',
                    'GitHub runner package SHA does not match RUNNERDECK_AGENT_SHA — aborted'
                );
            }
        } catch (\RuntimeException $e) {
            return self::fail('provision.pick_download', $e->getMessage());
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return self::fail('provision.mkdir', "failed to create {$dir}");
        }

        $archivePath = "$dir/" . $download['filename'];
        $fetch = Shell::exec(['curl', '-fsSL', '-o', $archivePath, $download['download_url']], 180);
        if ($fetch['code'] !== 0 || !is_file($archivePath)) {
            $message = 'failed to download runner package: ' . trim($fetch['stderr']);
            return self::fail('provision.download', $message, stderr: $fetch['stderr']);
        }

        $expectedSha = $download['sha256_checksum'] ?? null;
        $ghSha = is_string($expectedSha) && $expectedSha !== '' ? $expectedSha : null;
        if (!self::checksumMatches($archivePath, $ghSha)) {
            @unlink($archivePath);
            return self::fail('provision.checksum', 'downloaded runner package failed checksum verification — aborted');
        }
        $pin = Config::agentSha();
        if ($pin !== null && $ghSha === null && !self::checksumMatches($archivePath, $pin)) {
            @unlink($archivePath);
            return self::fail(
                'provision.pin',
                'downloaded runner package does not match RUNNERDECK_AGENT_SHA — aborted'
            );
        }

        $stage = $dir . '/.runnerdeck-extract';
        $commands = self::extractCommands($archivePath, $download['filename']);
        $extracted = self::extractWithFallback($archivePath, $stage, $commands);
        @unlink($archivePath);

        if (!$extracted['ok'] || !self::binariesPresent($stage)) {
            $stderr = $extracted['ok']
                ? 'extracted package has no ' . self::listenerRelative()
                : $extracted['stderr'];
            self::removeTree($stage);
            return self::fail('provision.extract', self::extractFailureMessage($stderr), stderr: $stderr);
        }
        if (!self::adoptExtract($stage, $dir)) {
            self::removeTree($stage);
            return self::fail('provision.extract', 'failed to move the extracted runner package into place');
        }
        self::removeTree($stage);

        if (!Platform::isWindows()) {
            Shell::exec(['chmod', '+x', "$dir/config.sh", "$dir/run.sh"], 5);
        }

        $deps = self::installOsDependencies($dir);
        if (!$deps['ok']) {
            return $deps;
        }

        return ['ok' => true, 'message' => "runner binaries installed ({$download['os']}/{$download['architecture']})"];
    }

    public static function ensureConfigured(string $dir, string $name, bool $ephemeral = false): array
    {
        $install = self::ensureInstalled($dir);
        if (!$install['ok']) {
            return $install;
        }

        if ($ephemeral) {
            SlotFlags::setEphemeral($dir, true);
        } else {
            $ephemeral = SlotFlags::isEphemeral($dir);
        }

        if (is_file("$dir/.runner")) {
            return ['ok' => true, 'message' => "{$name} already configured"];
        }

        $labels = Config::provisionLabelsFor($dir);
        if (Platform::isWindows() && !self::batchSafe($name, $labels)) {
            return self::unsafeForBatch($name);
        }

        try {
            $registrationUrl = self::registrationUrl();
            $token = GithubClient::registrationToken();
        } catch (\RuntimeException $e) {
            return self::fail('provision.registration_token', $e->getMessage(), $name);
        }
        if (Platform::isWindows() && !self::batchSafe($registrationUrl, $token)) {
            return self::unsafeForBatch($name);
        }

        if (!Platform::isWindows() && !is_file("$dir/config.sh")) {
            $found = implode(', ', array_slice(array_values(array_diff(scandir($dir) ?: [], ['.', '..'])), 0, 20));
            return self::fail(
                'provision.config',
                "config.sh is missing from {$dir} after installing the runner package (found: {$found})",
                $name
            );
        }

        $cmd = [
            ...(Platform::isWindows() ? ['cmd.exe', '/d', '/c', 'config.cmd'] : ["$dir/config.sh"]),
            '--url', $registrationUrl,
            '--token', $token,
            '--name', $name,
            '--labels', $labels,
            '--work', '_work',
            '--unattended',
            '--replace',
        ];
        if ($ephemeral) {
            $cmd[] = '--ephemeral';
        }
        $config = Shell::exec($cmd, 60, $dir);

        if ($config['code'] !== 0) {
            $output = trim($config['stderr'] . "\n" . $config['stdout']);
            $message = Platform::runnerScript('config') . " failed for {$name}: {$output}";
            return self::fail('provision.config', $message, $name, $output);
        }

        AgentRoster::remember($name);
        GithubClient::forgetRunnerList();
        return ['ok' => true, 'message' => "{$name} configured"];
    }

    private static function batchSafe(string ...$args): bool
    {
        foreach ($args as $arg) {
            if (!Platform::isSafeBatchArg($arg)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{ok: false, message: string} */
    private static function unsafeForBatch(string $name): array
    {
        return self::fail(
            'provision.config',
            "refusing to pass '{$name}' to config.cmd — use only letters, digits, dot, underscore and hyphen",
            $name
        );
    }

    /**
     * GitHub's Linux tarball ships bin/installdependencies.sh (libicu, libkrb5,
     * …), which needs root, so it runs through passwordless sudo when not root.
     * Skipped on macOS and Windows, and on Alpine: that script is apt/yum only; the image installs the
     * libs via apk instead.
     *
     * @return array{ok: bool, message: string}
     */
    public static function installOsDependencies(string $dir): array
    {
        $script = "$dir/bin/installdependencies.sh";
        if (!is_file($script)) {
            return ['ok' => true, 'message' => 'no OS dependency script'];
        }
        if (Platform::isWindows() || PHP_OS_FAMILY === 'Darwin') {
            return ['ok' => true, 'message' => 'no OS libraries to install on this platform'];
        }
        if (is_file('/etc/alpine-release')) {
            return ['ok' => true, 'message' => 'skipping installdependencies.sh on Alpine'];
        }

        Shell::exec(['chmod', '+x', $script], 5);
        $cmd = ['bash', $script];
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0;
        if (!$root && is_executable('/usr/bin/sudo')) {
            $cmd = ['sudo', '-n', ...$cmd];
        }
        $deps = Shell::exec($cmd, 180, $dir);
        if ($deps['code'] !== 0) {
            $output = trim($deps['stderr'] . "\n" . $deps['stdout']);
            $message = 'failed to install runner OS libraries (libicu, libkrb5, …): '
                . $output
                . ' — the .NET runner binary will not start without them';
            return self::fail('provision.os_deps', $message, stderr: $output);
        }

        return ['ok' => true, 'message' => 'runner OS libraries installed'];
    }

    /**
     * @return array{ok: false, message: string}
     */
    private static function fail(string $action, string $message, ?string $runner = null, string $stderr = ''): array
    {
        AppLog::error($action, $message, ['runner' => $runner, 'stderr' => $stderr]);
        return ['ok' => false, 'message' => $message];
    }

    /** Removes local registration artifacts so ensureConfigured() treats this slot as fresh again. */
    public static function deregister(string $dir): void
    {
        foreach (['.runner', '.credentials', '.credentials_rsaparams'] as $file) {
            @unlink("$dir/$file");
        }
    }

    private static function registrationUrl(): string
    {
        $owner = Config::scope() === 'repo' ? Config::repo() : Config::org();
        return 'https://github.com/' . $owner;
    }

    public static function binariesPresent(string $dir): bool
    {
        return is_file($dir . '/' . Platform::runnerScript('config'))
            && is_file($dir . '/' . self::listenerRelative());
    }

    /** @return array{ok: bool, message: string} */
    public static function probeExtract(string $dir): array
    {
        $probe = $dir . '/.runnerdeck-extract-probe';
        self::removeTree($probe);
        if (!@mkdir($probe, 0700, true) && !is_dir($probe)) {
            return ['ok' => false, 'message' => "cannot create {$probe}"];
        }
        file_put_contents($probe . '/payload.txt', 'ok');
        $archive = $probe . '/probe.tar';
        $create = Shell::exec(['tar', '-cf', $archive, '-C', $probe, 'payload.txt'], 20);
        if ($create['code'] !== 0 || !is_file($archive)) {
            $stderr = trim($create['stderr']) !== '' ? $create['stderr'] : 'could not create a probe archive';
            self::removeTree($probe);
            return ['ok' => false, 'message' => self::extractFailureMessage($stderr)];
        }

        $out = $probe . '/out';
        $extracted = self::extractWithFallback($archive, $out, [
            ['tar', '-xf', $archive, '-C', $out],
            ['bsdtar', '-xf', $archive, '-C', $out],
        ]);
        $ok = $extracted['ok'] && is_file($out . '/payload.txt');
        self::removeTree($probe);
        if ($ok) {
            return ['ok' => true, 'message' => 'can extract an archive into the pool directory'];
        }
        return ['ok' => false, 'message' => self::extractFailureMessage($extracted['stderr'])];
    }

    public static function extractFailureMessage(string $stderr): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $first = $lines[0] ?? 'extractor exited non-zero';
        if (strlen($first) > 180) {
            $first = substr($first, 0, 180) . '…';
        }
        $summary = count($lines) > 1 ? $first . ' (' . count($lines) . ' errors)' : $first;
        $hint = '';
        if (preg_match('/Function not implemented|ENOSYS/i', $stderr) === 1) {
            $hint = ' The host kernel or filesystem rejected a call the extractor needs (ENOSYS).'
                . ' A current kernel, or Docker, can extract the runner package.';
        }
        return 'failed to extract runner package: ' . $summary . '.' . $hint;
    }

    private static function listenerRelative(): string
    {
        return 'bin/' . (Platform::isWindows() ? 'Runner.Listener.exe' : 'Runner.Listener');
    }

    /**
     * @return list<list<string>>
     */
    private static function extractCommands(string $archive, string $filename): array
    {
        if (Platform::isWindows() || self::isZipArchive($filename)) {
            return [
                Platform::isWindows()
                    ? ['tar', '-xf', $archive, '-C', dirname($archive) . '/.runnerdeck-extract']
                    : ['unzip', '-q', $archive, '-d', dirname($archive) . '/.runnerdeck-extract'],
                ['bsdtar', '-xf', $archive, '-C', dirname($archive) . '/.runnerdeck-extract'],
            ];
        }
        $dest = dirname($archive) . '/.runnerdeck-extract';
        return [
            ['tar', 'xzf', $archive, '-C', $dest],
            ['bsdtar', '-xzf', $archive, '-C', $dest],
        ];
    }

    /**
     * @param list<list<string>> $commands
     * @return array{ok: bool, stderr: string}
     */
    private static function extractWithFallback(string $archive, string $dest, array $commands): array
    {
        $stderr = '';
        foreach ($commands as $i => $cmd) {
            if ($i > 0 && !self::commandOnPath((string) $cmd[0])) {
                continue;
            }
            self::removeTree($dest);
            if (!@mkdir($dest, 0700, true) && !is_dir($dest)) {
                return ['ok' => false, 'stderr' => "cannot create {$dest}"];
            }
            $result = Shell::exec($cmd, 120);
            if ($result['code'] === 0) {
                return ['ok' => true, 'stderr' => ''];
            }
            $stderr = $result['stderr'];
        }
        self::removeTree($dest);
        if ((!@mkdir($dest, 0700, true) && !is_dir($dest)) || !self::pharExtract($archive, $dest)) {
            self::removeTree($dest);
            return ['ok' => false, 'stderr' => $stderr];
        }
        return ['ok' => true, 'stderr' => ''];
    }

    private static function commandOnPath(string $bin): bool
    {
        $check = Platform::isWindows() ? 'where' : 'which';
        return Shell::exec([$check, $bin], 5)['code'] === 0;
    }

    private static function pharExtract(string $archive, string $dest): bool
    {
        if (!class_exists(\PharData::class)) {
            return false;
        }
        try {
            $phar = new \PharData($archive);
            $phar->extractTo($dest, null, true);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function adoptExtract(string $stage, string $dir): bool
    {
        $items = scandir($stage);
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $to = $dir . '/' . $name;
            if (is_link($to) || is_file($to)) {
                @unlink($to);
            } elseif (is_dir($to)) {
                self::removeTree($to);
            }
            if (!@rename($stage . '/' . $name, $to)) {
                return false;
            }
        }
        return true;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (is_array($items)) {
            foreach ($items as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                self::removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    public static function checksumMatches(string $path, ?string $expectedSha): bool
    {
        if ($expectedSha === null || $expectedSha === '') {
            return true;
        }
        $actualSha = hash_file('sha256', $path);
        return is_string($actualSha) && hash_equals(strtolower($expectedSha), strtolower($actualSha));
    }

    public static function isZipArchive(string $filename): bool
    {
        return str_ends_with($filename, '.zip');
    }

    /**
     * Pick the official runner package for this OS/arch from GitHub's downloads list.
     *
     * @param array<int, array<string, mixed>> $downloads
     * @return array<string, mixed>
     * @throws \RuntimeException if this OS/arch has no matching official package
     */
    public static function matchDownload(array $downloads, string $osFamily, string $machine): array
    {
        $osMap = ['Linux' => 'linux', 'Darwin' => 'osx', 'Windows' => 'win'];
        $os = $osMap[$osFamily] ?? null;
        if ($os === null) {
            throw new \RuntimeException('unsupported OS: ' . $osFamily);
        }

        $machine = strtolower($machine);
        $archMap = [
            'x86_64' => 'x64', 'amd64' => 'x64',
            'aarch64' => 'arm64', 'arm64' => 'arm64',
            'armv7l' => 'arm',
        ];
        $arch = $archMap[$machine] ?? null;
        if ($arch === null) {
            throw new \RuntimeException("unsupported architecture: {$machine}");
        }

        foreach ($downloads as $d) {
            if (($d['os'] ?? null) === $os && ($d['architecture'] ?? null) === $arch) {
                return $d;
            }
        }
        throw new \RuntimeException("no official runner package found for {$os}/{$arch}");
    }

    /** @throws \RuntimeException if this OS/arch has no matching official package */
    public static function pickDownloadForHost(): array
    {
        return self::pickDownload();
    }

    /** @param array<string, mixed> $download */
    public static function versionFromDownload(array $download): ?string
    {
        $filename = (string) ($download['filename'] ?? '');
        if (preg_match('/(\d+\.\d+\.\d+)/', $filename, $m)) {
            return $m[1];
        }
        return null;
    }

    /** @throws \RuntimeException if this OS/arch has no matching official package */
    private static function pickDownload(): array
    {
        return self::matchDownload(GithubClient::listRunnerDownloads(), PHP_OS_FAMILY, php_uname('m'));
    }
}
