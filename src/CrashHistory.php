<?php

declare(strict_types=1);

namespace RunnerDeck;

final class CrashHistory
{
    private const RETENTION_SECONDS = 7 * 24 * 60 * 60;

    private static function db(): \PDO
    {
        static $pdo = null;
        if ($pdo !== null) {
            return $pdo;
        }

        $path = History::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("failed to create {$dir}");
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS crash_events (
            ts INTEGER NOT NULL,
            runner_id TEXT NOT NULL,
            agent_name TEXT NOT NULL
        )');
        return $pdo;
    }

    public static function record(string $runnerId, string $agentName): void
    {
        try {
            $db = self::db();
            $db->prepare('INSERT INTO crash_events (ts, runner_id, agent_name) VALUES (?, ?, ?)')
                ->execute([time(), $runnerId, $agentName]);
            $db->prepare('DELETE FROM crash_events WHERE ts < ?')
                ->execute([time() - self::RETENTION_SECONDS]);
        } catch (\Throwable) {
            // Crash history is a nice-to-have; never let it take the dashboard down.
        }
    }

    /** @return array<string, int> runner id => crash count within the retention window */
    public static function countsByRunner(): array
    {
        try {
            $db = self::db();
            $stmt = $db->prepare(
                'SELECT runner_id, COUNT(*) AS n FROM crash_events WHERE ts >= ? GROUP BY runner_id'
            );
            $stmt->execute([time() - self::RETENTION_SECONDS]);

            $counts = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $counts[(string) $row['runner_id']] = (int) $row['n'];
            }
            return $counts;
        } catch (\Throwable) {
            return [];
        }
    }
}
