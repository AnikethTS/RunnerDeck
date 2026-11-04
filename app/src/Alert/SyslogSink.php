<?php

declare(strict_types=1);

namespace RunnerDeck\Alert;

use RunnerDeck\Core\AppLog;
use RunnerDeck\Core\Config;

/**
 * Optional RFC5424 datagram to a local syslog socket. Fail-open when the
 * socket is missing (typical on macOS without syslogd unix).
 */
final class SyslogSink
{
    /** @var (callable(string): bool)|null */
    private static $sendFake = null;

    /** @param (callable(string): bool)|null $handler */
    public static function fakeSend(?callable $handler): void
    {
        self::$sendFake = $handler;
    }

    public static function enabled(): bool
    {
        return Config::syslogEnabled();
    }

    public static function emit(string $event, string $message): void
    {
        if (!self::enabled()) {
            return;
        }
        $safeEvent = preg_replace('/[^A-Za-z0-9._-]/', '_', $event) ?? 'event';
        $text = AppLog::redact(str_replace(["\n", "\r"], ' ', $message));
        $line = self::format($safeEvent, $text);
        if (self::$sendFake !== null) {
            (self::$sendFake)($line);
            return;
        }
        self::send($line);
    }

    public static function format(string $event, string $message): string
    {
        $ts = gmdate('Y-m-d\TH:i:s') . 'Z';
        $host = gethostname();
        if (!is_string($host) || $host === '') {
            $host = 'localhost';
        }
        $host = str_replace(' ', '_', $host);
        $pri = 13;
        return '<' . $pri . '>1 ' . $ts . ' ' . $host . ' runnerdeck - ' . $event
            . ' - ' . $message;
    }

    private static function send(string $line): void
    {
        $path = Config::syslogSocket();
        if (!file_exists($path)) {
            return;
        }
        $sock = @stream_socket_client('unix://' . $path, $errno, $errstr, 0.2);
        if (!is_resource($sock)) {
            return;
        }
        stream_set_timeout($sock, 0, 200000);
        @fwrite($sock, $line);
        fclose($sock);
    }
}
