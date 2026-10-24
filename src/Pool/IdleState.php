<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use RunnerDeck\Core\Settings;

final class IdleState
{
    private const FILE = 'idle_state.json';

    /** @var (callable(): int)|null */
    private static $nowFake = null;

    /** @param (callable(): int)|null $handler */
    public static function fakeNow(?callable $handler): void
    {
        self::$nowFake = $handler;
    }

    /**
     * Track first-idle timestamps. Busy, unknown GitHub, stopped, and
     * Pause-AR slots drop out of the map.
     *
     * @param array<int, array<string, mixed>> $runners
     */
    public static function sync(array $runners): void
    {
        $prev = self::load();
        $next = [];
        foreach ($runners as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (empty($row['local_running']) || !empty($row['auto_restart_paused'])) {
                continue;
            }
            $gh = is_array($row['github'] ?? null) ? $row['github'] : null;
            if ($gh === null || !empty($gh['busy'])) {
                continue;
            }
            $next[$id] = isset($prev[$id]) ? (int) $prev[$id] : self::now();
        }
        self::write($next);
    }

    /**
     * Slot ids that have been GitHub-idle for at least $minutes.
     *
     * @return list<string>
     */
    public static function dueIds(int $minutes): array
    {
        if ($minutes < 1) {
            return [];
        }
        $need = $minutes * 60;
        $now = self::now();
        $out = [];
        foreach (self::load() as $id => $since) {
            if (($now - (int) $since) >= $need) {
                $out[] = (string) $id;
            }
        }
        return $out;
    }

    /** @return array<string, int> */
    private static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id => $since) {
            if (is_string($id) && $id !== '' && is_numeric($since) && (int) $since > 0) {
                $out[$id] = (int) $since;
            }
        }
        return $out;
    }

    /** @param array<string, int> $map */
    private static function write(array $map): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = self::path() . '.tmp';
        file_put_contents($tmp, json_encode($map, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, self::path());
    }

    private static function now(): int
    {
        return self::$nowFake !== null ? (self::$nowFake)() : time();
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }
}
