<?php

declare(strict_types=1);

namespace RunnerDeck\GitHub;

use RunnerDeck\Core\Settings;

final class CompletedRunCache
{
    private const FILE = 'queue_completed_runs.json';
    private const MAX_ENTRIES = 80;

    /** @return list<array<string, mixed>>|null */
    public static function get(string $key): ?array
    {
        $entries = self::read();
        $hit = $entries[$key] ?? null;
        return is_array($hit) ? array_values($hit) : null;
    }

    /** @param list<array<string, mixed>> $jobs */
    public static function put(string $key, array $jobs): void
    {
        $entries = self::read();
        unset($entries[$key]);
        $entries[$key] = $jobs;
        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES, null, true);
        }

        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($entries)) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }

    /** @return array<string, mixed> */
    private static function read(): array
    {
        $raw = @file_get_contents(self::path());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }
}
