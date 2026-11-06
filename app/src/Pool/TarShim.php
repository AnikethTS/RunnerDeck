<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use RunnerDeck\Configuration\Config;
use RunnerDeck\Configuration\Settings;
use RunnerDeck\System\Platform;
use RunnerDeck\System\Shell;

/**
 * GitHub's runner reads PATH from the slot's .path file and then calls tar
 * itself to unpack actions. A host where that tar returns ENOSYS can still
 * install the runner package (bsdtar / PharData), and every job then fails.
 * When bsdtar can extract, a tar wrapper is placed first on that PATH.
 */
final class TarShim
{
    public const SHIM_MESSAGE = 'Using bsdtar shim: system tar fails with ENOSYS';

    public const MISSING_MESSAGE = 'system tar fails with ENOSYS and bsdtar is not available.'
        . ' Install bsdtar so runner jobs can unpack actions.';

    public const BOTH_MESSAGE = 'system tar and bsdtar both fail with ENOSYS.'
        . ' Runner jobs that unpack actions will fail.';

    private const CACHE_SECONDS = 600;

    public static function directory(): string
    {
        return Settings::storageDir() . '/shims';
    }

    /**
     * Install or remove the shim, and put it first in this slot's .path.
     * Null when the runner should keep the PATH it already has.
     */
    public static function prepare(string $runnerDir): ?string
    {
        if (Platform::isWindows()) {
            return null;
        }
        $result = self::probe($runnerDir);
        $dir = self::directory();
        if ($result['shim']) {
            self::prependPathFile($runnerDir, $dir);
            return $dir;
        }
        if ($result['ok']) {
            self::stripPathFile($runnerDir, $dir);
        }
        return null;
    }

    /** Dashboard line. Empty when tar works or the probe did not run. */
    public static function notice(): string
    {
        $cached = self::readCache();
        if ($cached !== null) {
            return $cached;
        }
        $root = self::probeRoot();
        if ($root === null) {
            return '';
        }
        return self::probe($root)['banner'];
    }

    /**
     * @return array{ok: bool, message: string, shim: bool, banner: string}
     */
    public static function probe(string $dir): array
    {
        $probe = $dir . '/.runnerdeck-extract-probe';
        self::removeTree($probe);
        $result = self::classify($probe);
        self::removeTree($probe);
        self::remember($result['banner']);
        if ($result['shim'] && !Platform::isWindows()) {
            self::install($result['bsdtar']);
        } elseif ($result['ok']) {
            self::removeScript();
        }
        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'shim' => $result['shim'],
            'banner' => $result['banner'],
        ];
    }

    public static function prependPathFile(string $runnerDir, string $shimDir): void
    {
        $file = $runnerDir . '/.path';
        $shimDir = rtrim($shimDir, '/');
        $existing = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($existing === '') {
            $existing = (string) getenv('PATH');
        }
        $parts = self::without($existing, $shimDir);
        array_unshift($parts, $shimDir);
        file_put_contents($file, implode(':', $parts) . "\n");
    }

    /**
     * @return array{ok: bool, message: string, shim: bool, banner: string, bsdtar: string}
     */
    private static function classify(string $probe): array
    {
        $fail = static function (string $message, string $banner = ''): array {
            return [
                'ok' => false,
                'message' => $message,
                'shim' => false,
                'banner' => $banner,
                'bsdtar' => '',
            ];
        };
        if (!@mkdir($probe, 0700, true) && !is_dir($probe)) {
            return $fail("cannot create {$probe}");
        }
        $payload = $probe . '/payload.txt';
        file_put_contents($payload, 'ok');
        $archive = $probe . '/probe.tar';
        if (!self::writeArchive($probe, $archive, $payload)) {
            return $fail(Provisioner::extractFailureMessage('could not create a probe archive'));
        }

        $out = $probe . '/out';
        $tar = self::extract('tar', $archive, $out);
        if ($tar['ok']) {
            return [
                'ok' => true,
                'message' => 'can extract an archive into the pool directory',
                'shim' => false,
                'banner' => '',
                'bsdtar' => '',
            ];
        }
        if (!self::isEnosys($tar['stderr'])) {
            return $fail(Provisioner::extractFailureMessage($tar['stderr']));
        }

        $bsdtar = self::bsdtarBinary();
        if ($bsdtar === null) {
            return $fail(self::MISSING_MESSAGE, self::MISSING_MESSAGE);
        }
        $bsd = self::extract($bsdtar, $archive, $out);
        if ($bsd['ok']) {
            return [
                'ok' => true,
                'message' => self::SHIM_MESSAGE,
                'shim' => true,
                'banner' => self::SHIM_MESSAGE,
                'bsdtar' => $bsdtar,
            ];
        }
        if (self::isEnosys($bsd['stderr'])) {
            return $fail(self::BOTH_MESSAGE, self::BOTH_MESSAGE);
        }
        return $fail(Provisioner::extractFailureMessage($bsd['stderr']));
    }

    private static function writeArchive(string $probe, string $archive, string $payload): bool
    {
        $created = Shell::exec(['tar', '-cf', $archive, '-C', $probe, 'payload.txt'], 20);
        if ($created['code'] === 0 && is_file($archive)) {
            return true;
        }
        if (is_file($archive)) {
            @unlink($archive);
        }
        $bsdtar = self::bsdtarBinary();
        if ($bsdtar !== null) {
            $created = Shell::exec([$bsdtar, '-cf', $archive, '-C', $probe, 'payload.txt'], 20);
            if ($created['code'] === 0 && is_file($archive)) {
                return true;
            }
            if (is_file($archive)) {
                @unlink($archive);
            }
        }
        try {
            $phar = new \PharData($archive);
            $phar->addFile($payload, 'payload.txt');
            return is_file($archive);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{ok: bool, stderr: string} */
    private static function extract(string $bin, string $archive, string $dest): array
    {
        self::removeTree($dest);
        if (!@mkdir($dest, 0700, true) && !is_dir($dest)) {
            return ['ok' => false, 'stderr' => "cannot create {$dest}"];
        }
        $result = Shell::exec([$bin, '-xf', $archive, '-C', $dest], 20);
        $ok = $result['code'] === 0 && is_file($dest . '/payload.txt');
        return ['ok' => $ok, 'stderr' => $result['stderr']];
    }

    private static function install(string $bsdtar): void
    {
        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $script = $dir . '/tar';
        $body = "#!/bin/sh\nexec " . escapeshellarg($bsdtar) . " \"$@\"\n";
        file_put_contents($script, $body);
        chmod($script, 0755);
    }

    private static function removeScript(): void
    {
        $script = self::directory() . '/tar';
        if (is_file($script)) {
            @unlink($script);
        }
    }

    private static function stripPathFile(string $runnerDir, string $shimDir): void
    {
        $file = $runnerDir . '/.path';
        if (!is_file($file)) {
            return;
        }
        $shimDir = rtrim($shimDir, '/');
        $existing = trim((string) file_get_contents($file));
        if (!in_array($shimDir, explode(':', $existing), true)) {
            return;
        }
        $parts = self::without($existing, $shimDir);
        if ($parts === []) {
            $parts = self::without((string) getenv('PATH'), $shimDir);
        }
        file_put_contents($file, implode(':', $parts) . "\n");
    }

    /** @return list<string> */
    private static function without(string $path, string $shimDir): array
    {
        $parts = [];
        foreach (explode(':', $path) as $part) {
            if ($part !== '' && $part !== $shimDir) {
                $parts[] = $part;
            }
        }
        return $parts;
    }

    private static function bsdtarBinary(): ?string
    {
        $result = Shell::exec(['which', 'bsdtar'], 5);
        if ($result['code'] !== 0) {
            return null;
        }
        $own = self::directory() . '/';
        foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with($line, '/') && !str_starts_with($line, $own)) {
                return $line;
            }
        }
        return null;
    }

    private static function isEnosys(string $stderr): bool
    {
        return preg_match('/Function not implemented|ENOSYS/i', $stderr) === 1;
    }

    private static function probeRoot(): ?string
    {
        $poolDir = Config::poolDir();
        if (is_dir($poolDir) && is_writable($poolDir)) {
            return $poolDir;
        }
        if (!is_dir($poolDir) && is_writable(dirname($poolDir))) {
            return dirname($poolDir);
        }
        return null;
    }

    private static function remember(string $banner): void
    {
        $dir = Settings::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $encoded = json_encode(['banner' => $banner, 'at' => time()]) ?: '{}';
        file_put_contents($dir . '/tar-shim.json', $encoded);
    }

    private static function readCache(): ?string
    {
        $path = Settings::storageDir() . '/tar-shim.json';
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_string($decoded['banner'] ?? null) || !is_int($decoded['at'] ?? null)) {
            return null;
        }
        if (time() - $decoded['at'] > self::CACHE_SECONDS) {
            return null;
        }
        return $decoded['banner'];
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
}
