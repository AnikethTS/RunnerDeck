<?php

declare(strict_types=1);

namespace RunnerDeck\Configuration;

/**
 * The packaged launcher reads this file before it creates a data directory,
 * so a later terminal uses the pool you chose without a shell rc export.
 */
final class LauncherPaths
{
    public static function poolFile(): string
    {
        $config = getenv('XDG_CONFIG_HOME');
        $base = is_string($config) && $config !== '' ? $config : self::home() . '/.config';
        return $base . '/runnerdeck/pool-dir';
    }

    public static function readPoolDir(): ?string
    {
        $raw = @file_get_contents(self::poolFile());
        if (!is_string($raw)) {
            return null;
        }
        $dir = trim($raw);
        return $dir === '' ? null : $dir;
    }

    /** @return array{ok: bool, message: string} */
    public static function savePoolDir(string $dir): array
    {
        $dir = trim($dir);
        if ($dir === '') {
            $path = self::poolFile();
            if (is_file($path)) {
                @unlink($path);
            }
            return ['ok' => true, 'message' => 'pool-dir cleared. The next launch uses the default data directory.'];
        }
        if (preg_match('/\s/', $dir) === 1) {
            return [
                'ok' => false,
                'message' => 'pool-dir cannot contain whitespace. GitHub Actions starts each step'
                    . ' with an unquoted path, so a space fails the job before the script runs.',
            ];
        }
        if (!self::isAbsolute($dir)) {
            return ['ok' => false, 'message' => 'pool-dir must be an absolute path'];
        }

        $path = self::poolFile();
        $parent = dirname($path);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
            return ['ok' => false, 'message' => "failed to create {$parent}"];
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $dir . "\n") === false) {
            return ['ok' => false, 'message' => "failed to write {$path}"];
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => "failed to write {$path}"];
        }
        return ['ok' => true, 'message' => "pool-dir saved. The next launch uses {$dir}."];
    }

    private static function home(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }
        $profile = getenv('USERPROFILE');
        return is_string($profile) ? $profile : '';
    }

    private static function isAbsolute(string $dir): bool
    {
        return str_starts_with($dir, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $dir) === 1;
    }
}
