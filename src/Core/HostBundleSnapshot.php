<?php

declare(strict_types=1);

namespace RunnerDeck\Core;

/**
 * Optional daily copy of the disaster-recovery JSON into storage/bundles.
 * TOTP is never included. Crash webhook is omitted unless the operator
 * opts in. Fail-open on disk errors.
 */
final class HostBundleSnapshot
{
    private const KEEP = 7;

    /** @var (callable(): int)|null */
    private static $nowFake = null;

    public static function fakeNow(?callable $fn): void
    {
        self::$nowFake = $fn;
    }

    public static function tick(): void
    {
        if (!Config::bundleSnapshotEnabled()) {
            return;
        }
        $day = gmdate('Y-m-d', self::now());
        $dir = self::dir();
        $path = $dir . '/host-' . $day . '.json';
        if (is_file($path)) {
            return;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        try {
            $bundle = HostBundle::export(Config::bundleSnapshotSecrets());
        } catch (\Throwable) {
            return;
        }
        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return;
        }
        self::prune($dir);
        AppLog::info('host.bundle.snapshot', 'wrote ' . basename($path));
    }

    private static function prune(string $dir): void
    {
        $files = glob($dir . '/host-*.json') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, self::KEEP) as $old) {
            @unlink($old);
        }
    }

    private static function dir(): string
    {
        return Settings::storageDir() . '/bundles';
    }

    private static function now(): int
    {
        return self::$nowFake !== null ? (self::$nowFake)() : time();
    }
}
