<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use RunnerDeck\Core\Config;

final class SlotFlags
{
    private const FILE = '.runnerdeck-ephemeral';
    private const LABELS = '.runnerdeck-labels';

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

    /** @return list<string> */
    public static function extraLabels(string $dir): array
    {
        $path = $dir . '/' . self::LABELS;
        if ($dir === '' || !is_file($path)) {
            return [];
        }
        $parsed = Config::parseLabels(trim((string) file_get_contents($path)));
        return $parsed ?? [];
    }

    /** @param list<string> $labels */
    public static function setExtraLabels(string $dir, array $labels): void
    {
        $path = $dir . '/' . self::LABELS;
        if ($labels === []) {
            @unlink($path);
            return;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($path, implode(',', $labels) . "\n");
        chmod($path, 0600);
    }
}
