<?php

declare(strict_types=1);

namespace RunnerDeck;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::requestIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function requestIsHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) !== 'https') {
            return false;
        }
        return Config::trustProxy() || self::remoteIsLoopback();
    }

    private static function remoteIsLoopback(): bool
    {
        $addr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $addr === '127.0.0.1' || $addr === '::1' || $addr === '::ffff:127.0.0.1';
    }
}
