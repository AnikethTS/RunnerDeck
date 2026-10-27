<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use RunnerDeck\Core\AppLog;
use RunnerDeck\Core\Config;

/**
 * Delete old runner.log.1 and _diag files on idle slots. Fail-open.
 * Never touches a slot whose process is running, and never deletes _work.
 */
final class LogRetention
{
    /** @var (callable(): int)|null */
    private static $nowFake = null;

    public static function fakeNow(?callable $fn): void
    {
        self::$nowFake = $fn;
    }

    public static function tick(): int
    {
        $days = Config::logRetentionDays();
        if ($days === null) {
            return 0;
        }
        $cutoff = self::now() - ($days * 86400);
        $removed = 0;
        try {
            foreach (RunnerPool::discover(0) as $info) {
                if ($info->localRunning) {
                    continue;
                }
                $removed += self::pruneDir($info->dir, $cutoff);
            }
        } catch (\Throwable) {
            return $removed;
        }
        if ($removed > 0) {
            AppLog::info('log.retention', 'pruned ' . $removed . ' old log file(s)');
        }
        return $removed;
    }

    public static function pruneDir(string $dir, int $cutoff): int
    {
        $removed = 0;
        $rotated = $dir . '/' . RunnerLog::ROTATED;
        if (self::isOldFile($rotated, $cutoff) && @unlink($rotated)) {
            $removed++;
        }
        $current = $dir . '/' . RunnerLog::FILE;
        if (self::isOldFile($current, $cutoff) && @unlink($current)) {
            $removed++;
        }
        $diag = $dir . '/_diag';
        if (is_dir($diag)) {
            $removed += self::pruneTree($diag, $cutoff);
        }
        return $removed;
    }

    private static function pruneTree(string $dir, int $cutoff): int
    {
        $removed = 0;
        $items = @scandir($dir);
        if (!is_array($items)) {
            return 0;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $removed += self::pruneTree($path, $cutoff);
                continue;
            }
            if (self::isOldFile($path, $cutoff) && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    private static function isOldFile(string $path, int $cutoff): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $mtime = @filemtime($path);
        return $mtime !== false && $mtime < $cutoff;
    }

    private static function now(): int
    {
        return self::$nowFake !== null ? (self::$nowFake)() : time();
    }
}
