<?php

declare(strict_types=1);

namespace RunnerDeck;

final class HostMode
{
    private const FILE = 'host_mode.json';

    public static function isMaintenance(): bool
    {
        return !empty(self::load()['maintenance']);
    }

    public static function isRebootReady(): bool
    {
        return !empty(self::load()['reboot_ready']);
    }

    public static function setMaintenance(bool $on): void
    {
        $was = self::isMaintenance();
        $data = self::load();
        $data['maintenance'] = $on;
        if ($on) {
            $data['reboot_ready'] = false;
        }
        self::write($data);
        if ($on && !$was) {
            CrashWebhook::notifyEvent(
                'maintenance',
                'RunnerDeck: this host entered maintenance — runners will not start until you resume.'
            );
        }
    }

    public static function setRebootReady(bool $on): void
    {
        $was = self::isRebootReady();
        $data = self::load();
        $data['reboot_ready'] = $on;
        self::write($data);
        if ($on && !$was) {
            CrashWebhook::notifyEvent(
                'reboot_ready',
                'RunnerDeck: pool is stopped. Safe to reboot this machine.'
            );
        }
    }

    /** Write flags without firing the host-mode webhook (disaster-recovery restore). */
    public static function restore(bool $maintenance, bool $rebootReady): void
    {
        self::write([
            'maintenance' => $maintenance,
            'reboot_ready' => $rebootReady && !$maintenance,
        ]);
    }

    public static function blockStartMessage(): ?string
    {
        if (!self::isMaintenance()) {
            return null;
        }
        return 'This host is in maintenance — resume before starting runners';
    }

    /** @return array{maintenance?: bool, reboot_ready?: bool} */
    private static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    private static function write(array $data): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = self::path() . '.tmp';
        file_put_contents($tmp, json_encode([
            'maintenance' => !empty($data['maintenance']),
            'reboot_ready' => !empty($data['reboot_ready']),
        ], JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, self::path());
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }
}
