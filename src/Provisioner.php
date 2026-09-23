<?php

declare(strict_types=1);

namespace RunnerDeck;

final class Provisioner
{
    public static function ensureInstalled(string $dir): array
    {
        if (is_file("$dir/config.sh")) {
            return ['ok' => true, 'message' => 'runner binaries already present'];
        }

        try {
            $download = self::pickDownload();
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
        if (!self::checksumMatches($archivePath, is_string($expectedSha) ? $expectedSha : null)) {
            @unlink($archivePath);
            return self::fail('provision.checksum', 'downloaded runner package failed checksum verification — aborted');
        }

        $extract = self::isZipArchive($download['filename'])
            ? Shell::exec(['unzip', '-q', $archivePath, '-d', $dir], 120)
            : Shell::exec(['tar', 'xzf', $archivePath, '-C', $dir], 120);
        @unlink($archivePath);

        if ($extract['code'] !== 0) {
            $message = 'failed to extract runner package: ' . trim($extract['stderr']);
            return self::fail('provision.extract', $message, stderr: $extract['stderr']);
        }

        Shell::exec(['chmod', '+x', "$dir/config.sh", "$dir/run.sh"], 5);

        $deps = self::installOsDependencies($dir);
        if (!$deps['ok']) {
            return $deps;
        }

        return ['ok' => true, 'message' => "runner binaries installed ({$download['os']}/{$download['architecture']})"];
    }

    public static function ensureConfigured(string $dir, string $name): array
    {
        $install = self::ensureInstalled($dir);
        if (!$install['ok']) {
            return $install;
        }

        if (is_file("$dir/.runner")) {
            return ['ok' => true, 'message' => "{$name} already configured"];
        }

        try {
            $registrationUrl = self::registrationUrl();
            $token = GithubClient::registrationToken();
        } catch (\RuntimeException $e) {
            return self::fail('provision.registration_token', $e->getMessage(), $name);
        }

        $config = Shell::exec([
            './config.sh',
            '--url', $registrationUrl,
            '--token', $token,
            '--name', $name,
            '--labels', Config::label(),
            '--work', '_work',
            '--unattended',
            '--replace',
        ], 60, $dir);

        if ($config['code'] !== 0) {
            $output = trim($config['stderr'] . "\n" . $config['stdout']);
            $message = "config.sh failed for {$name}: {$output}";
            return self::fail('provision.config', $message, $name, $output);
        }

        return ['ok' => true, 'message' => "{$name} configured"];
    }

    /**
     * GitHub's Linux tarball ships bin/installdependencies.sh (libicu, libkrb5,
     * …). Skip on Alpine: that script is apt/yum only; the image installs the
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
        if (is_file('/etc/alpine-release')) {
            return ['ok' => true, 'message' => 'skipping installdependencies.sh on Alpine'];
        }

        Shell::exec(['chmod', '+x', $script], 5);
        $deps = Shell::exec(['bash', $script], 180, $dir);
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
    private static function pickDownload(): array
    {
        return self::matchDownload(GithubClient::listRunnerDownloads(), PHP_OS_FAMILY, php_uname('m'));
    }
}
