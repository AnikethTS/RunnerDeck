<?php

declare(strict_types=1);

namespace RunnerDeck\Host;

use RunnerDeck\Core\Config;
use RunnerDeck\Core\Settings;

final class WatchHeartbeat
{
    private const FILE = 'watch_heartbeat.json';
    private const STALE_SECONDS = 20;

    /** @var (callable(): int)|null */
    private static $nowFake = null;

    public static function fakeNow(?callable $fn): void
    {
        self::$nowFake = $fn;
    }

    public static function touch(): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode(['ticked_at' => self::now()], JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    /**
     * Banner when watch-backed features are on but the loop has not ticked.
     */
    public static function warning(bool $hasEphemeral): ?string
    {
        if (!self::needed($hasEphemeral)) {
            return null;
        }
        if (self::isFresh()) {
            return null;
        }
        return 'watch-auto-restart.php has not ticked — auto-restart, scale, idle-stop, '
            . 'queue/long-job alerts, and daily host-bundle snapshots are idle until run.sh '
            . 'or the watch unit is running.';
    }

    public static function isFresh(): bool
    {
        $path = self::path();
        if (!is_file($path)) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $ticked = is_array($decoded) ? (int) ($decoded['ticked_at'] ?? 0) : 0;
        if ($ticked < 1) {
            return false;
        }
        return (self::now() - $ticked) < self::STALE_SECONDS;
    }

    private static function needed(bool $hasEphemeral): bool
    {
        return $hasEphemeral
            || Config::autoRestartEnabled()
            || Config::scaleToQueueEnabled()
            || Config::idleStopMinutes() !== null
            || Config::bundleSnapshotEnabled()
            || (Config::queueWaitMinutes() !== null && Config::crashWebhookUrl() !== null)
            || (Config::longJobMinutes() !== null && Config::crashWebhookUrl() !== null);
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
