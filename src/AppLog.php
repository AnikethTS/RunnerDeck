<?php

declare(strict_types=1);

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
}
