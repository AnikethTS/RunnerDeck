<?php

declare(strict_types=1);

// No login exists in this app; the session-bound token is the only defense
// against a forged request from another page.
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';
    private const FIELD = 'csrf_token';
    private const HEADER = 'HTTP_X_CSRF_TOKEN';

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function token(): string
    {
        self::ensureSession();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function verifyRequest(): bool
    {
        self::ensureSession();
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $submitted = $_POST[self::FIELD] ?? $_SERVER[self::HEADER] ?? '';
        return is_string($submitted) && $submitted !== '' && hash_equals($expected, $submitted);
    }
}
