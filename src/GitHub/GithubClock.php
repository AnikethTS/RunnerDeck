<?php

declare(strict_types=1);

namespace RunnerDeck\GitHub;

use RunnerDeck\Core\Settings;

/**
 * Compare this host’s clock to GitHub’s Date header. Missing/unparseable
 * Date fails open (no banner).
 */
final class GithubClock
{
    private const FILE = 'gh_clock.json';
    private const SKEW_SECONDS = 300;

    /** @var (callable(): int)|null */
    private static $nowFake = null;

    public static function fakeNow(?callable $fn): void
    {
        self::$nowFake = $fn;
    }

    public static function observeHttpDate(?string $httpDate): void
    {
        if ($httpDate === null) {
            return;
        }
        $trimmed = trim($httpDate);
        if ($trimmed === '') {
            return;
        }
        $github = strtotime($trimmed);
        if ($github === false) {
            return;
        }
        $now = self::now();
        self::write([
            'github_unix' => $github,
            'host_unix' => $now,
            'delta' => $github - $now,
        ]);
    }

    public static function warning(): ?string
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['delta'])) {
            return null;
        }
        $delta = (int) $decoded['delta'];
        $abs = abs($delta);
        if ($abs < self::SKEW_SECONDS) {
            return null;
        }
        $minutes = max(1, (int) round($abs / 60));
        $side = $delta > 0 ? 'behind' : 'ahead of';
        return "This host's clock is about " . $minutes . ' min ' . $side
            . ' GitHub — TOTP and GitHub App JWTs can fail. Sync NTP.';
    }

    /** @param array<string, int> $state */
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
