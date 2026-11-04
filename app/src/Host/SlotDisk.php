<?php

declare(strict_types=1);

namespace RunnerDeck\Host;

use RunnerDeck\Core\Platform;
use RunnerDeck\Core\Shell;

final class SlotDisk
{
    private const CACHE_SECONDS = 30.0;

    /** @var array{at: float, byDir: array<string, array{kb: ?int, can_clear: bool}>}|null */
    private static ?array $cache = null;

    /** @param list<string> $dirs */
    public static function prime(array $dirs): void
    {
        $now = microtime(true);
        $fresh = self::$cache !== null && ($now - self::$cache['at']) < self::CACHE_SECONDS;
        $todo = [];
        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            if ($fresh && isset(self::$cache['byDir'][$dir])) {
                continue;
            }
            $todo[] = $dir;
        }
        if ($todo === []) {
            return;
        }

        $byDir = ($fresh && self::$cache !== null) ? self::$cache['byDir'] : [];
        $at = ($fresh && self::$cache !== null) ? self::$cache['at'] : $now;
        $paths = [];
        $owner = [];
        foreach ($todo as $dir) {
            $canClear = is_dir($dir . '/_work');
            $hasPath = false;
            foreach (['_work', '_diag'] as $sub) {
                $path = $dir . '/' . $sub;
                if (is_dir($path)) {
                    $paths[] = $path;
                    $owner[$path] = $dir;
                    $hasPath = true;
                }
            }
            $log = $dir . '/runner.log';
            if (is_file($log)) {
                $paths[] = $log;
                $owner[$log] = $dir;
                $hasPath = true;
            }
            $byDir[$dir] = [
                'kb' => $hasPath ? null : 0,
                'can_clear' => $canClear,
            ];
        }
        self::$cache = ['at' => $at, 'byDir' => $byDir];
        if ($paths === []) {
            return;
        }

        $result = self::du($paths);
        if ($result['code'] !== 0) {
            return;
        }
        $kbByDir = [];
        foreach (explode("\n", trim($result['stdout'])) as $line) {
            if (preg_match('/^(\d+)\s+(.+)$/', $line, $m) !== 1) {
                continue;
            }
            $dir = $owner[$m[2]] ?? null;
            if ($dir === null) {
                continue;
            }
            $kbByDir[$dir] = ($kbByDir[$dir] ?? 0) + (int) $m[1];
        }
        foreach ($kbByDir as $dir => $kb) {
            $byDir[$dir] = [
                'kb' => $kb,
                'can_clear' => $byDir[$dir]['can_clear'],
            ];
        }
        self::$cache = ['at' => $at, 'byDir' => $byDir];
    }

    /**
     * Best-effort size of `_work`, `_diag`, and `runner.log`.
     *
     * @return array{kb: ?int, can_clear: bool}
     */
    public static function inspect(string $dir): array
    {
        $now = microtime(true);
        if (
            self::$cache !== null
            && ($now - self::$cache['at']) < self::CACHE_SECONDS
            && isset(self::$cache['byDir'][$dir])
        ) {
            return self::$cache['byDir'][$dir];
        }

        $canClear = is_dir($dir . '/_work');
        $paths = [];
        foreach (['_work', '_diag'] as $sub) {
            $path = $dir . '/' . $sub;
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }
        $log = $dir . '/runner.log';
        if (is_file($log)) {
            $paths[] = $log;
        }

        $kb = 0;
        if ($paths !== []) {
            $result = self::du($paths);
            $kb = self::parseDuKb($result);
        }

        $info = ['kb' => $kb, 'can_clear' => $canClear];
        if (self::$cache === null || ($now - self::$cache['at']) >= self::CACHE_SECONDS) {
            self::$cache = ['at' => $now, 'byDir' => []];
        }
        self::$cache['byDir'][$dir] = $info;
        return $info;
    }

    public static function clearWork(string $dir): array
    {
        $work = $dir . '/_work';
        if (!is_dir($work)) {
            return ['ok' => true, 'message' => 'no work directory to clear'];
        }
        self::removeTree($work);
        clearstatcache(true, $work);
        if (is_dir($work)) {
            return ['ok' => false, 'message' => 'failed to clear work dir'];
        }
        self::$cache = null;
        return ['ok' => true, 'message' => 'work directory cleared'];
    }

    /**
     * @param list<string> $paths
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function du(array $paths): array
    {
        if (!Platform::isWindows()) {
            return Shell::exec(array_merge(['du', '-sk'], $paths), 8);
        }
        $lines = [];
        foreach ($paths as $path) {
            $bytes = 0;
            if (is_file($path)) {
                $bytes = (int) @filesize($path);
            } elseif (is_dir($path)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($items as $item) {
                    if ($item->isFile() && !$item->isLink()) {
                        $bytes += (int) $item->getSize();
                    }
                }
            }
            $lines[] = (int) ceil($bytes / 1024) . "\t" . $path;
        }
        return ['code' => 0, 'stdout' => implode("\n", $lines), 'stderr' => ''];
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    public static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $target = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($target);
            } else {
                @unlink($target);
            }
        }
        @rmdir($path);
    }

    /**
     * @param array{code: int, stdout: string, stderr: string} $result
     */
    private static function parseDuKb(array $result): ?int
    {
        if ($result['code'] !== 0) {
            return null;
        }
        $sum = 0;
        $parsed = false;
        foreach (explode("\n", trim($result['stdout'])) as $line) {
            if (preg_match('/^(\d+)\s+/', $line, $m) === 1) {
                $sum += (int) $m[1];
                $parsed = true;
            }
        }
        return $parsed ? $sum : null;
    }

    public static function formatKb(?int $kb): string
    {
        if ($kb === null) {
            return '—';
        }
        if ($kb >= 1024 * 1024) {
            return number_format($kb / (1024 * 1024), 1) . ' GB';
        }
        if ($kb >= 1024) {
            return (string) (int) round($kb / 1024) . ' MB';
        }
        return $kb . ' KB';
    }
}
