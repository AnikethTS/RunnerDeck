<?php

declare(strict_types=1);

namespace RunnerDeck;

final class HostMode
{
    private const FILE = 'host_mode.json';

    public static function isMaintenance(): bool
    {
        $path = self::path();
        if (!is_file($path)) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) && !empty($decoded['maintenance']);
    }

    public static function setMaintenance(bool $on): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = self::path() . '.tmp';
        file_put_contents($tmp, json_encode(['maintenance' => $on], JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, self::path());
    }

    public static function blockStartMessage(): ?string
    {
        if (!self::isMaintenance()) {
            return null;
        }
        return 'This host is in maintenance — resume before starting runners';
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }
}
