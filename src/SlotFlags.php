<?php

declare(strict_types=1);

namespace RunnerDeck;

final class SlotFlags
{
    private const FILE = '.runnerdeck-ephemeral';

    public static function isEphemeral(string $dir): bool
    {
        return is_file($dir . '/' . self::FILE);
    }

    public static function setEphemeral(string $dir, bool $on): void
    {
        $path = $dir . '/' . self::FILE;
        if (!$on) {
            @unlink($path);
            return;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($path, "1\n");
        chmod($path, 0600);
    }
}
