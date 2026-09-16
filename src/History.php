<?php

declare(strict_types=1);

namespace RunnerDeck;

// Rolling pool-wide CPU/RAM history, sampled once per Dashboard::snapshot()
// call (the existing poll cycle is the sampler — no separate daemon/cron).
// Best-effort throughout: a missing pdo_sqlite extension or a write failure
// degrades to "no history," never breaks the rest of the dashboard.
final class History
{
    private const RETENTION_SECONDS = 3600;

    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    private static function path(): string
    {
        return getenv('RUNNERDECK_HISTORY_FILE') ?: dirname(__DIR__) . '/storage/db/history.sqlite';
    }

    private static function db(): \PDO
    {
        static $pdo = null;
        if ($pdo !== null) {
            return $pdo;
        }

        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("failed to create {$dir}");
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS samples (
            ts INTEGER NOT NULL,
            avg_cpu REAL NOT NULL,
            total_rss_kb INTEGER NOT NULL
        )');
        return $pdo;
    }

    public static function record(float $avgCpu, int $totalRssKb): void
    {
        try {
            $db = self::db();
            $db->prepare('INSERT INTO samples (ts, avg_cpu, total_rss_kb) VALUES (?, ?, ?)')
                ->execute([time(), $avgCpu, $totalRssKb]);
            $db->prepare('DELETE FROM samples WHERE ts < ?')
                ->execute([time() - self::RETENTION_SECONDS]);
        } catch (\Throwable) {
            // History is a nice-to-have; never let it take the dashboard down.
        }
    }

    /** @return array<int, array{ts: int, avg_cpu: float, total_rss_kb: int}> */
    public static function recent(): array
    {
        try {
            $db = self::db();
            $stmt = $db->prepare('SELECT ts, avg_cpu, total_rss_kb FROM samples WHERE ts >= ? ORDER BY ts ASC');
            $stmt->execute([time() - self::RETENTION_SECONDS]);
            return array_map(static fn($row) => [
                'ts' => (int) $row['ts'],
                'avg_cpu' => (float) $row['avg_cpu'],
                'total_rss_kb' => (int) $row['total_rss_kb'],
            ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Throwable) {
            return [];
        }
    }
}
