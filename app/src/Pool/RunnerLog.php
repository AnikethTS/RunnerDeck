<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

final class RunnerLog
{
    public const FILE = 'runner.log';
    public const ROTATED = 'runner.log.1';
    private const MAX_BYTES = 5_242_880;

    private static ?int $maxBytesFake = null;

    /** @var array<string, array{stamp: string, lines: list<string>}> */
    private static array $tailCache = [];

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

    /**
     * Existing files, oldest first (`runner.log.1` then `runner.log`).
     *
     * @return list<string>
     */
    public static function files(string $dir): array
    {
        $out = [];
        foreach ([self::ROTATED, self::FILE] as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                $out[] = $path;
            }
        }
        return $out;
    }

    /**
     * Newest last. Pulls from the current file, then fills from `runner.log.1`
     * so a rotation does not empty the dashboard tail.
     *
     * @return list<string>
     */
    public static function tail(string $dir, int $lines): array
    {
        if ($lines <= 0) {
            return [];
        }
        $stamp = self::stamp($dir . '/' . self::FILE) . '|' . self::stamp($dir . '/' . self::ROTATED);
        $key = $dir . "\n" . $lines;
        if (isset(self::$tailCache[$key]) && self::$tailCache[$key]['stamp'] === $stamp) {
            return self::$tailCache[$key]['lines'];
        }
        $read = self::readTail($dir, $lines);
        self::$tailCache[$key] = ['stamp' => $stamp, 'lines' => $read];
        return $read;
    }

    /** @return list<string> */
    private static function readTail(string $dir, int $lines): array
    {
        $current = self::tailFile($dir . '/' . self::FILE, $lines);
        $need = $lines - count($current);
        if ($need <= 0) {
            return $current;
        }
        $older = self::tailFile($dir . '/' . self::ROTATED, $need);
        return array_merge($older, $current);
    }

    private static function stamp(string $path): string
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return 'missing';
        }
        $mtime = filemtime($path);
        $size = filesize($path);
        return ($mtime === false ? '0' : (string) $mtime) . ':' . ($size === false ? '0' : (string) $size);
    }

    /**
     * @return list<string>
     */
    public static function tailFile(string $path, int $lines): array
    {
        if (!is_file($path) || $lines <= 0) {
            return [];
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return [];
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }

        $chunkSize = 8192;
        $pos = $size;
        $data = '';
        while ($pos > 0 && substr_count($data, "\n") <= $lines) {
            $read = min($chunkSize, $pos);
            $pos -= $read;
            fseek($fh, $pos);
            $data = fread($fh, $read) . $data;
        }
        fclose($fh);

        $allLines = explode("\n", rtrim((string) $data, "\n"));
        return array_map(self::cleanLine(...), array_slice($allLines, -$lines));
    }

    private static function cleanLine(string $line): string
    {
        $line = rtrim($line, "\r");
        if (preg_match('//u', $line) === 1) {
            return $line;
        }
        if (function_exists('iconv')) {
            $converted = @iconv('CP437', 'UTF-8//IGNORE', $line);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return (string) preg_replace('/[^\x20-\x7E]/', '?', $line);
    }
}
