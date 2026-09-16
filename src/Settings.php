<?php

declare(strict_types=1);

namespace RunnerDeck;

// Real env vars and .env still win over this; see Config::bootstrapEnv().
final class Settings
{
    private const KEYS = [
        'RUNNERDECK_SCOPE', 'RUNNERDECK_ORG', 'RUNNERDECK_REPO', 'RUNNERDECK_LABEL', 'RUNNERDECK_CHECK_UPDATES',
        'RUNNERDECK_AUTH_TOTP_SECRET', 'RUNNERDECK_AUTO_RESTART',
    ];

    private static function path(): string
    {
        return getenv('RUNNERDECK_SETTINGS_FILE') ?: dirname(__DIR__) . '/storage/settings.json';
    }

    /** The directory settings.json lives in — reused by Auth.php for its lockout file, same storage location. */
    public static function storageDir(): string
    {
        return dirname(self::path());
    }

    /** @return array<string, string> */
    public static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($decoded)) {
            return [];
        }

        $values = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    public static function isConfigured(): bool
    {
        $saved = self::load();
        $scope = $saved['RUNNERDECK_SCOPE'] ?? 'org';
        return $scope === 'repo' ? isset($saved['RUNNERDECK_REPO']) : isset($saved['RUNNERDECK_ORG']);
    }

    /** Applies saved settings into the environment; never overrides a real ambient env var. */
    public static function applyToEnv(): void
    {
        foreach (self::load() as $key => $value) {
            if (in_array($key, self::KEYS, true) && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }

    /** @param array<string, string> $values */
    public static function save(array $values): void
    {
        $clean = [];
        foreach (self::KEYS as $key) {
            if (isset($values[$key]) && $values[$key] !== '') {
                $clean[$key] = $values[$key];
            }
        }

        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("failed to create {$dir}");
        }

        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = self::path() . '.tmp';
        file_put_contents($tmp, $json);
        chmod($tmp, 0600);
        rename($tmp, self::path());
    }
}
