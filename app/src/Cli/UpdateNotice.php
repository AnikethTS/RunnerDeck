<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

use RunnerDeck\Configuration\Config;
use RunnerDeck\Configuration\Settings;
use RunnerDeck\GitHub\GithubBackoff;
use RunnerDeck\GitHub\UpdateCheck;

/**
 * One line at the start of a terminal command when a newer release exists.
 * GitHub is asked at most once a day. A failed check stays quiet.
 */
final class UpdateNotice
{
    private const FILE = 'cli-update.json';
    private const MAX_AGE = 86400;
    private const RELEASE = 'https://github.com/AnikethTS/RunnerDeck/releases/tag/v';

    /** @var (callable(): array{current: string, latest: ?string, update_available: bool, error: ?string})|null */
    private static $checker = null;

    /** @param (callable(): array{current: string, latest: ?string, update_available: bool, error: ?string})|null $fn */
    public static function fakeChecker(?callable $fn): void
    {
        self::$checker = $fn;
    }

    public static function line(bool $refresh): ?string
    {
        if (getenv('RUNNERDECK_NO_UPDATE_NOTICE') === '1') {
            return null;
        }
        $saved = self::read();
        $age = $saved === null ? self::MAX_AGE : time() - $saved['checked_at'];
        if ($refresh && $age >= self::MAX_AGE && !GithubBackoff::isCooling()) {
            $saved = self::refresh();
        }
        if ($saved === null || $saved['latest'] === '') {
            return null;
        }
        if (!version_compare($saved['latest'], Config::version(), '>')) {
            return null;
        }

        return '↑ RunnerDeck ' . $saved['latest'] . ' is available (you have ' . Config::version()
            . "). Run: runnerdeck update\n" . self::RELEASE . $saved['latest'];
    }

    /** @return array{checked_at: int, latest: string} */
    private static function refresh(): array
    {
        $info = self::$checker !== null ? (self::$checker)() : UpdateCheck::check();
        $latest = '';
        if (
            $info['error'] === null
            && is_string($info['latest'])
            && preg_match('/^[0-9A-Za-z.+-]+$/', $info['latest']) === 1
        ) {
            $latest = $info['latest'];
        }
        $saved = ['checked_at' => time(), 'latest' => $latest];
        self::write($saved);

        return $saved;
    }

    /** @return array{checked_at: int, latest: string}|null */
    private static function read(): ?array
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $latest = $decoded['latest'] ?? '';

        return [
            'checked_at' => (int) ($decoded['checked_at'] ?? 0),
            'latest' => is_string($latest) ? $latest : '',
        ];
    }

    /** @param array{checked_at: int, latest: string} $saved */
    private static function write(array $saved): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($saved)) === false) {
            return;
        }
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }
}
