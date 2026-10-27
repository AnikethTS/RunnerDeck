<?php

declare(strict_types=1);

namespace RunnerDeck\GitHub;

use RunnerDeck\Core\Settings;

/**
 * Last GitHub X-RateLimit-* from gh api --include. Missing headers fail open.
 */
final class GithubQuota
{
    private const FILE = 'gh_quota.json';
    private const LOW_REMAINING = 100;

    /** @var (callable(): int)|null */
    private static $nowFake = null;

    public static function fakeNow(?callable $fn): void
    {
        self::$nowFake = $fn;
    }

    public static function observe(?int $remaining, ?int $limit, ?int $reset): void
    {
        if ($remaining === null) {
            return;
        }
        self::write([
            'remaining' => $remaining,
            'limit' => $limit,
            'reset' => $reset,
            'observed_at' => self::now(),
        ]);
    }

    /** @return array{remaining: ?int, limit: ?int, reset: ?int} */
    public static function snapshot(): array
    {
        $state = self::load();
        if ($state === []) {
            return ['remaining' => null, 'limit' => null, 'reset' => null];
        }
        return [
            'remaining' => isset($state['remaining']) ? (int) $state['remaining'] : null,
            'limit' => isset($state['limit']) ? (int) $state['limit'] : null,
            'reset' => isset($state['reset']) ? (int) $state['reset'] : null,
        ];
    }

    public static function warning(): ?string
    {
        $snap = self::snapshot();
        $remaining = $snap['remaining'];
        if ($remaining === null) {
            return null;
        }
        $limit = $snap['limit'];
        $low = $remaining < self::LOW_REMAINING;
        if (!$low && $limit !== null && $limit > 0) {
            $low = ($remaining / $limit) <= 0.1;
        }
        if (!$low) {
            return null;
        }
        $text = 'GitHub API quota is low (' . $remaining
            . ($limit !== null ? '/' . $limit : '')
            . ' remaining)';
        $reset = $snap['reset'];
        if ($reset !== null && $reset > self::now()) {
            $mins = max(1, (int) ceil(($reset - self::now()) / 60));
            $text .= ' — resets in about ' . $mins . ' min';
        }
        return $text . '.';
    }

    /** @return array<string, mixed> */
    private static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private static function write(array $state): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
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
