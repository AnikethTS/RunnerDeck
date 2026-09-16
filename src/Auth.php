<?php

declare(strict_types=1);

namespace RunnerDeck;

final class Auth
{
    private const SESSION_KEY = 'authenticated';
    private const PENDING_SECRET_KEY = 'pending_totp_secret';
    private const LOCKOUT_FILE = 'auth_lockout.json';
    private const MAX_FAILURES = 5;
    private const LOCKOUT_SECONDS = 300;

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    private static function lockoutPath(): string
    {
        return Settings::storageDir() . '/' . self::LOCKOUT_FILE;
    }

    /** @return array<string, int> */
    private static function readLockoutFile(): array
    {
        $path = self::lockoutPath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($path) ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, int> $data */
    private static function writeLockoutFile(array $data): void
    {
        $path = self::lockoutPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    public static function isEnabled(): bool
    {
        return Config::authEnabled();
    }

    public static function isLoggedIn(): bool
    {
        self::ensureSession();
        return ($_SESSION[self::SESSION_KEY] ?? false) === true;
    }

    /** @return array{locked: bool, retryAfter?: int} */
    public static function lockoutStatus(): array
    {
        $lockedUntil = (int) (self::readLockoutFile()['lockedUntil'] ?? 0);
        if ($lockedUntil > time()) {
            return ['locked' => true, 'retryAfter' => $lockedUntil - time()];
        }
        return ['locked' => false];
    }

    public static function attempt(string $code): bool
    {
        self::ensureSession();
        if (!self::isEnabled() || self::lockoutStatus()['locked']) {
            return false;
        }

        $secret = Config::authTotpSecret();
        $ok = $secret !== null && Totp::verify($secret, $code);
        self::recordAttempt($ok);
        if ($ok) {
            self::login();
        }
        return $ok;
    }

    public static function login(): void
    {
        self::ensureSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
    }

    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function saveTotpSecret(string $secret): void
    {
        Settings::save(array_merge(Settings::load(), ['RUNNERDECK_AUTH_TOTP_SECRET' => $secret]));
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . $secret);
        @unlink(self::lockoutPath());
    }

    public static function beginTotpSetup(): string
    {
        self::ensureSession();
        $secret = Totp::generateSecret();
        $_SESSION[self::PENDING_SECRET_KEY] = $secret;
        return $secret;
    }

    public static function pendingTotpSecret(): ?string
    {
        self::ensureSession();
        $secret = $_SESSION[self::PENDING_SECRET_KEY] ?? null;
        return is_string($secret) ? $secret : null;
    }

    public static function confirmTotpSetup(string $code): bool
    {
        self::ensureSession();
        $secret = $_SESSION[self::PENDING_SECRET_KEY] ?? null;
        if (!is_string($secret) || !Totp::verify($secret, $code)) {
            return false;
        }
        self::saveTotpSecret($secret);
        unset($_SESSION[self::PENDING_SECRET_KEY]);
        self::login();
        return true;
    }

    private static function recordAttempt(bool $success): void
    {
        if ($success) {
            @unlink(self::lockoutPath());
            return;
        }

        $data = self::readLockoutFile();
        $lockedUntil = (int) ($data['lockedUntil'] ?? 0);
        $failures = ($lockedUntil > 0 && $lockedUntil <= time()) ? 1 : (int) ($data['failures'] ?? 0) + 1;

        $write = ['failures' => $failures];
        if ($failures >= self::MAX_FAILURES) {
            $write['lockedUntil'] = time() + self::LOCKOUT_SECONDS;
        }
        self::writeLockoutFile($write);
    }
}
