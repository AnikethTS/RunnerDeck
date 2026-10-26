<?php

declare(strict_types=1);

namespace RunnerDeck\GitHub;

use RunnerDeck\Core\AppLog;
use RunnerDeck\Core\Settings;

/**
 * Pause gh api polls after HTTP 429 / secondary rate limit. Unknown or
 * unreadable state fails open (calls go through).
 */
final class GithubBackoff
{
    private const FILE = 'gh_backoff.json';
    private const BASE_SECONDS = 30;
    private const MAX_SECONDS = 900;

    /** @var (callable(): int)|null */
    private static $nowFake = null;

    public static function fakeNow(?callable $fn): void
    {
        self::$nowFake = $fn;
    }

    public static function looksLimited(string $text): bool
    {
        $m = strtolower($text);
        $needles = [
            '429',
            'rate limit exceeded',
            'secondary rate limit',
            'abuse detection',
            'you have exceeded a secondary',
            'api rate limit',
        ];
        foreach ($needles as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function isCooling(): bool
    {
        return self::until() > self::now();
    }

    public static function remaining(): int
    {
        return max(0, self::until() - self::now());
    }

    public static function warning(): ?string
    {
        $left = self::remaining();
        if ($left < 1) {
            return null;
        }
        return 'GitHub API is rate-limited — pausing polls for about '
            . self::formatSeconds($left) . ' so this host does not hammer GitHub.';
    }

    public static function coolingMessage(): string
    {
        $left = self::remaining();
        return 'GitHub API cooling down (' . self::formatSeconds($left)
            . ' left; HTTP 429 / rate limit)';
    }

    /** @throws \RuntimeException */
    public static function throwIfCooling(): void
    {
        if (!self::isCooling()) {
            return;
        }
        throw new \RuntimeException(self::coolingMessage());
    }

    public static function noteFailure(string $text): void
    {
        if (!self::looksLimited($text)) {
            return;
        }
        $state = self::load();
        $consecutive = (int) ($state['consecutive'] ?? 0);
        $delay = self::retryAfterSeconds($text);
        if ($delay === null) {
            $delay = min(self::MAX_SECONDS, self::BASE_SECONDS * (2 ** min(8, $consecutive)));
        }
        $until = self::now() + max(1, $delay);
        self::write([
            'until' => $until,
            'consecutive' => $consecutive + 1,
            'last_error' => substr(trim($text), 0, 400),
        ]);
        AppLog::errorThrottled(
            'gh.backoff',
            'GitHub rate limit — cooling ' . self::formatSeconds($delay)
        );
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function retryAfterSeconds(string $text): ?int
    {
        if (preg_match('/retry-after:\s*(\d+)/i', $text, $m) === 1) {
            $n = (int) $m[1];
            if ($n > 0) {
                return min(self::MAX_SECONDS, $n);
            }
        }
        return null;
    }

    private static function until(): int
    {
        $state = self::load();
        return (int) ($state['until'] ?? 0);
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

    private static function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $minutes = (int) ceil($seconds / 60);
        return $minutes . ' min';
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
