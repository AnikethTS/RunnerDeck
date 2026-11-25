<?php

declare(strict_types=1);

namespace RunnerDeck\Presentation;

use RunnerDeck\Configuration\Config;

/** The Settings page note shown when the process environment or .env wins over the saved file. */
final class SettingsOverride
{
    public static function note(string $key): string
    {
        if (!Config::overriddenByEnv($key)) {
            return '';
        }
        $name = htmlspecialchars($key, ENT_QUOTES);
        return '<p class="settings-override">Overridden by the environment <code>' . $name . '</code></p>';
    }

    /** Secret fields stay blank when the environment supplies them, so the page does not print the value. */
    public static function fieldValue(string $key, string $value): string
    {
        if ($value !== '' && self::hidesValue($key) && Config::overriddenByEnv($key)) {
            return '';
        }
        return $value;
    }

    private static function hidesValue(string $key): bool
    {
        return $key === 'RUNNERDECK_AUTH_TOTP_SECRET' || $key === 'RUNNERDECK_CRASH_WEBHOOK_URL';
    }
}
