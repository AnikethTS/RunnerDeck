<?php

declare(strict_types=1);

namespace RunnerDeck;

final class SecurityHeaders
{
    private static ?string $nonce = null;
    private static bool $sent = false;

    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        }
        return self::$nonce;
    }

    public static function nonceAttr(): string
    {
        return ' nonce="' . htmlspecialchars(self::nonce(), ENT_QUOTES, 'UTF-8') . '"';
    }

    /** @return list<string> */
    public static function lines(): array
    {
        $nonce = self::nonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
        return [
            'X-Frame-Options: DENY',
            'X-Content-Type-Options: nosniff',
            'Referrer-Policy: no-referrer',
            'Content-Security-Policy: ' . $csp,
        ];
    }

    public static function send(): void
    {
        if (self::$sent || headers_sent()) {
            return;
        }
        self::$sent = true;
        foreach (self::lines() as $line) {
            header($line);
        }
    }
}
