<?php

declare(strict_types=1);

namespace RunnerDeck\Core;

final class SettingsPortable
{
    private const SKIP = [
        'RUNNERDECK_AUTH_TOTP_SECRET',
        'RUNNERDECK_CRASH_WEBHOOK_URL',
    ];

    /**
     * Saved settings minus TOTP secret and webhook URL.
     *
     * @return array<string, string>
     */
    public static function export(): array
    {
        $out = [];
        foreach (Settings::load() as $key => $value) {
            if (in_array($key, self::SKIP, true)) {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Overlay portable keys onto the current file. Never writes TOTP.
     * Omitting the webhook URL keeps the one already saved.
     *
     * @param array<mixed, mixed> $incoming
     * @return array{ok: bool, message: string}
     */
    public static function import(array $incoming): array
    {
        $current = Settings::load();
        $merged = $current;
        $applied = 0;
        foreach ($incoming as $key => $value) {
            if (!is_string($key) || $key === 'RUNNERDECK_AUTH_TOTP_SECRET') {
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            if (!in_array($key, Settings::keys(), true)) {
                continue;
            }
            if ($value === '') {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
            $applied++;
        }
        if ($applied < 1) {
            return ['ok' => false, 'message' => 'import had no recognised settings keys'];
        }
        $merged['RUNNERDECK_AUTH_TOTP_SECRET'] = $current['RUNNERDECK_AUTH_TOTP_SECRET'] ?? '';
        Settings::save($merged);
        AppLog::info('settings.import', 'imported ' . $applied . ' setting(s)');
        return ['ok' => true, 'message' => 'imported ' . $applied . ' setting(s)'];
    }
}
