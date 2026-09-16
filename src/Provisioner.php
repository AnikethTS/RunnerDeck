<?php

declare(strict_types=1);

final class Provisioner
{
    public static function ensureInstalled(string $dir): array
    {
        if (is_file("$dir/config.sh")) {
            return ['ok' => true, 'message' => 'runner binaries already present'];
        }

        try {
            $download = self::pickDownload();
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => "failed to create {$dir}"];
        }

        $archivePath = "$dir/" . $download['filename'];
        $fetch = Shell::exec(['curl', '-fsSL', '-o', $archivePath, $download['download_url']], 180);
        if ($fetch['code'] !== 0 || !is_file($archivePath)) {
            return ['ok' => false, 'message' => 'failed to download runner package: ' . trim($fetch['stderr'])];
        }

        $expectedSha = $download['sha256_checksum'] ?? null;
        if (!self::checksumMatches($archivePath, is_string($expectedSha) ? $expectedSha : null)) {
            @unlink($archivePath);
            return ['ok' => false, 'message' => 'downloaded runner package failed checksum verification — aborted'];
        }

        $extract = self::isZipArchive($download['filename'])
            ? Shell::exec(['unzip', '-q', $archivePath, '-d', $dir], 120)
            : Shell::exec(['tar', 'xzf', $archivePath, '-C', $dir], 120);
        @unlink($archivePath);

        if ($extract['code'] !== 0) {
            return ['ok' => false, 'message' => 'failed to extract runner package: ' . trim($extract['stderr'])];
        }

        Shell::exec(['chmod', '+x', "$dir/config.sh", "$dir/run.sh"], 5);

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
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
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
            return ['ok' => false, 'message' => "config.sh failed for {$name}: {$output}"];
        }

        return ['ok' => true, 'message' => "{$name} configured"];
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
     * @throws RuntimeException if this OS/arch has no matching official package
     */
    public static function matchDownload(array $downloads, string $osFamily, string $machine): array
    {
        $osMap = ['Linux' => 'linux', 'Darwin' => 'osx', 'Windows' => 'win'];
        $os = $osMap[$osFamily] ?? null;
        if ($os === null) {
            throw new RuntimeException('unsupported OS: ' . $osFamily);
        }

        $archMap = [
            'x86_64' => 'x64', 'amd64' => 'x64',
            'aarch64' => 'arm64', 'arm64' => 'arm64',
            'armv7l' => 'arm',
        ];
        $arch = $archMap[$machine] ?? null;
        if ($arch === null) {
            throw new RuntimeException("unsupported architecture: {$machine}");
        }

        foreach ($downloads as $d) {
            if (($d['os'] ?? null) === $os && ($d['architecture'] ?? null) === $arch) {
                return $d;
            }
        }
        throw new RuntimeException("no official runner package found for {$os}/{$arch}");
    }

    /** @throws RuntimeException if this OS/arch has no matching official package */
    private static function pickDownload(): array
    {
        return self::matchDownload(GithubClient::listRunnerDownloads(), PHP_OS_FAMILY, php_uname('m'));
    }
}
