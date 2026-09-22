<?php

declare(strict_types=1);

namespace RunnerDeck;

final class AppLog
{
    private const FILE = 'runnerdeck.log';
    private const ROTATED = 'runnerdeck.log.1';
    private const MAX_BYTES = 262144;
    private const STDERR_CHARS = 500;
    private const THROTTLE_SECONDS = 60;

    private static ?int $maxBytesFake = null;

    /** @var array<string, int> */
    private static array $lastWriteAt = [];

    /** Test seam: cap size before rotate. Pass null to restore. */
    public static function fakeMaxBytes(?int $bytes): void
    {
        self::$maxBytesFake = $bytes;
    }

    public static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }

    /**
     * @param array{runner?: ?string, stderr?: string} $context
     */
    public static function error(string $action, string $message, array $context = []): void
    {
        self::write($action, $message, $context);
    }

    /**
     * Same as error(), but at most once per action per minute — for poll paths
     * (GitHub list-runners) that would otherwise fill the log every 5s.
     *
     * @param array{runner?: ?string, stderr?: string} $context
     */
    public static function errorThrottled(string $action, string $message, array $context = []): void
    {
        $now = time();
        $prev = self::$lastWriteAt[$action] ?? 0;
        if ($now - $prev < self::THROTTLE_SECONDS) {
            return;
        }
        self::$lastWriteAt[$action] = $now;
        self::write($action, $message, $context);
    }

    public static function redact(string $text): string
    {
        $secret = Config::authTotpSecret();
        if ($secret !== null && $secret !== '') {
            $text = str_replace($secret, '[redacted]', $text);
        }

        $text = preg_replace('/--token\s+\S+/', '--token [redacted]', $text) ?? $text;
        $text = preg_replace('/\b(ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9_]{8,}/', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\bgithub_pat_[A-Za-z0-9_]{8,}/', '[redacted]', $text) ?? $text;
        return $text;
    }

    /**
     * @param array{runner?: ?string, stderr?: string} $context
     */
    private static function write(string $action, string $message, array $context): void
    {
        $dir = Settings::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $path = self::path();
        $stderr = self::redact(trim((string) ($context['stderr'] ?? '')));
        if (strlen($stderr) > self::STDERR_CHARS) {
            $stderr = substr($stderr, 0, self::STDERR_CHARS) . '…';
        }

        $record = [
            'time' => gmdate('c'),
            'action' => $action,
            'message' => self::redact($message),
        ];
        $runner = $context['runner'] ?? null;
        if (is_string($runner) && $runner !== '') {
            $record['runner'] = $runner;
        }
        if ($stderr !== '') {
            $record['stderr'] = $stderr;
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }

        $max = self::$maxBytesFake ?? self::MAX_BYTES;
        if (is_file($path) && filesize($path) >= $max) {
            $rotated = $dir . '/' . self::ROTATED;
            @unlink($rotated);
            @rename($path, $rotated);
        }

        error_log($line, 3, $path);
        @chmod($path, 0600);
    }

    /**
     * Newest last. Includes the rotated file so a just-rotated line is not lost.
     *
     * @return list<array{time: string, action: string, message: string, runner?: string, stderr?: string}>
     */
    public static function recent(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $dir = Settings::storageDir();
        $lines = array_merge(
            self::readRecords($dir . '/' . self::ROTATED),
            self::readRecords(self::path())
        );
        if (count($lines) > $limit) {
            return array_slice($lines, -$limit);
        }
        return $lines;
    }

    /**
     * @return list<array{time: string, action: string, message: string, runner?: string, stderr?: string}>
     */
    private static function readRecords(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $action = $decoded['action'] ?? null;
            $message = $decoded['message'] ?? null;
            $time = $decoded['time'] ?? null;
            if (!is_string($action) || !is_string($message) || !is_string($time)) {
                continue;
            }
            $entry = [
                'time' => $time,
                'action' => $action,
                'message' => $message,
            ];
            $runner = $decoded['runner'] ?? null;
            if (is_string($runner) && $runner !== '') {
                $entry['runner'] = $runner;
            }
            $stderr = $decoded['stderr'] ?? null;
            if (is_string($stderr) && $stderr !== '') {
                $entry['stderr'] = $stderr;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
