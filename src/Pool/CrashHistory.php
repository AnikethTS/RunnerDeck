<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use RunnerDeck\Host\History;

final class CrashHistory
{
    private const RETENTION_SECONDS = 7 * 24 * 60 * 60;
    public const LIST_LIMIT = 100;
    public const EXPORT_LIMIT = 5000;

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

    /**
     * @return list<array{ts: int, runner_id: string, agent_name: string}>
     */
    public static function exportEvents(int $limit = self::EXPORT_LIMIT): array
    {
        $limit = max(1, min(self::EXPORT_LIMIT, $limit));
        try {
            $db = self::db();
            $stmt = $db->prepare(
                'SELECT ts, runner_id, agent_name FROM crash_events WHERE ts >= ? ORDER BY ts DESC LIMIT '
                . $limit
            );
            $stmt->execute([time() - self::RETENTION_SECONDS]);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[] = [
                    'ts' => (int) $row['ts'],
                    'runner_id' => (string) $row['runner_id'],
                    'agent_name' => (string) $row['agent_name'],
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    public static function replaceEvents(array $events): int
    {
        try {
            $db = self::db();
            $db->exec('DELETE FROM crash_events');
            $stmt = $db->prepare(
                'INSERT INTO crash_events (ts, runner_id, agent_name) VALUES (?, ?, ?)'
            );
            $n = 0;
            $cutoff = time() - self::RETENTION_SECONDS;
            foreach ($events as $row) {
                if ($n >= self::EXPORT_LIMIT) {
                    break;
                }
                $ts = (int) ($row['ts'] ?? 0);
                $id = (string) ($row['runner_id'] ?? '');
                $name = (string) ($row['agent_name'] ?? '');
                if ($ts < $cutoff || $id === '' || !RunnerPool::isValidId($id)) {
                    continue;
                }
                $stmt->execute([$ts, $id, $name !== '' ? $name : $id]);
                $n++;
            }
            return $n;
        } catch (\Throwable) {
            return 0;
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

    /**
     * Newest first, capped. Empty list when sqlite is unavailable.
     *
     * @return array<string, list<int>> runner id => unix timestamps
     */
    public static function timestampsByRunner(): array
    {
        try {
            $db = self::db();
            $stmt = $db->prepare(
                'SELECT runner_id, ts FROM crash_events WHERE ts >= ? ORDER BY ts DESC'
            );
            $stmt->execute([time() - self::RETENTION_SECONDS]);

            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $id = (string) $row['runner_id'];
                if (!isset($out[$id])) {
                    $out[$id] = [];
                }
                if (count($out[$id]) >= self::LIST_LIMIT) {
                    continue;
                }
                $out[$id][] = (int) $row['ts'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
