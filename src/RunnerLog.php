<?php

declare(strict_types=1);

namespace RunnerDeck;

final class RunnerLog
{
    public const FILE = 'runner.log';
    public const ROTATED = 'runner.log.1';
    private const MAX_BYTES = 5_242_880;

    private static ?int $maxBytesFake = null;

    public static function fakeMaxBytes(?int $bytes): void
    {
        self::$maxBytesFake = $bytes;
    }

    public static function rotateIfOversized(string $dir): void
    {
        $path = $dir . '/' . self::FILE;
        if (!is_file($path)) {
            return;
        }
        $max = self::$maxBytesFake ?? self::MAX_BYTES;
        $size = @filesize($path);
        if ($size === false || $size < $max) {
            return;
        }
        $rotated = $dir . '/' . self::ROTATED;
        @unlink($rotated);
        @rename($path, $rotated);
    }
}
