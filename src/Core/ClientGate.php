<?php

declare(strict_types=1);

namespace RunnerDeck\Core;

/**
 * Second network gate on public Host / public client IP.
 * Loopback Host or REMOTE_ADDR, Tailscale CGNAT, and IPv6 ULA skip this
 * (TOTP rules still apply). Empty allowlist + mTLS off is fail-closed.
 */
final class ClientGate
{
    /**
     * Test seam: when set, deny calls this instead of sending HTTP 403.
     *
     * @var (callable(string): void)|null
     */
    public static $denyHandler = null;

    public static function extraGateApplies(): bool
    {
        return !self::skipExtraGate();
    }

    /** Skip, allowlist match, or verified mTLS. */
    public static function extraGateOk(): bool
    {
        if (self::skipExtraGate()) {
            return true;
        }

        return self::allowlistMatch() || self::mtlsVerified();
    }

    public static function blocked(): bool
    {
        return self::extraGateApplies() && !self::allowlistMatch() && !self::mtlsVerified();
    }

    public static function enforce(string $format = 'html'): void
    {
        if (!self::blocked()) {
            return;
        }
        if (self::$denyHandler !== null) {
            (self::$denyHandler)($format);
            return;
        }
        self::sendDenied($format);
    }

    public static function skipExtraGate(): bool
    {
        $host = HostingWarnings::requestHost();
        if (self::hostIsLoopbackName($host)) {
            return true;
        }
        $remote = self::canonicalIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (self::ipIsLoopback($remote)) {
            return true;
        }

        return self::ipIsPrivateOverlay($remote);
    }

    public static function allowlistMatch(): bool
    {
        $cidrs = Config::ipAllowlistCidrs();
        if ($cidrs === []) {
            return false;
        }
        $ip = self::clientIpForAllowlist();
        if ($ip === '') {
            return false;
        }
        foreach ($cidrs as $cidr) {
            if (self::ipInNetwork($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function mtlsVerified(): bool
    {
        if (!Config::requireMtls()) {
            return false;
        }
        $verify = strtoupper((string) (
            $_SERVER['SSL_CLIENT_VERIFY']
            ?? $_SERVER['HTTP_SSL_CLIENT_VERIFY']
            ?? ''
        ));

        return $verify === 'SUCCESS';
    }

    public static function clientIpForAllowlist(): string
    {
        if (Config::trustProxy()) {
            $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                $left = trim(explode(',', $xff)[0]);
                $parsed = self::canonicalIp(self::stripIpBracketsAndPort($left));
                if (self::isValidIp($parsed)) {
                    return $parsed;
                }
            }
        }

        return self::canonicalIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /**
     * Public Host arrived from a loopback proxy, and neither allowlist nor
     * mTLS is configured. The extra gate is skipped, so the page still loads.
     */
    public static function proxyHidesExtraGate(): bool
    {
        $host = HostingWarnings::requestHost();
        if ($host === '' || self::hostIsLoopbackName($host)) {
            return false;
        }
        $remote = self::canonicalIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!self::ipIsLoopback($remote)) {
            return false;
        }

        return Config::ipAllowlistCidrs() === [] && !Config::requireMtls();
    }

    public static function extraGateFact(): string
    {
        if (self::proxyHidesExtraGate()) {
            return 'Not applied (proxy is loopback)';
        }
        if (self::skipExtraGate()) {
            return 'Skipped (loopback or tailnet)';
        }
        if (self::allowlistMatch()) {
            return 'Allowlist';
        }
        if (self::mtlsVerified()) {
            return 'mTLS';
        }

        return 'Missing';
    }

    public static function ipInNetwork(string $ip, string $network): bool
    {
        $ip = self::canonicalIp($ip);
        $network = trim($network);
        $ipBin = inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        if (!str_contains($network, '/')) {
            $netBin = inet_pton(self::canonicalIp($network));

            return $netBin !== false && $ipBin === $netBin;
        }
        [$subnet, $bits] = explode('/', $network, 2);
        $subnet = self::canonicalIp(trim($subnet));
        $netBin = inet_pton($subnet);
        if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
            return false;
        }
        if (!ctype_digit($bits)) {
            return false;
        }
        $prefix = (int) $bits;
        $max = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $max) {
            return false;
        }
        $mask = str_repeat("\xff", intdiv($prefix, 8));
        $rest = $prefix % 8;
        if ($rest > 0) {
            $mask .= chr((0xFF << (8 - $rest)) & 0xFF);
        }
        $mask = str_pad($mask, strlen($ipBin), "\0");

        return ($ipBin & $mask) === ($netBin & $mask);
    }

    public static function isValidCidrOrIp(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }
        if (!str_contains($entry, '/')) {
            return self::isValidIp(self::canonicalIp($entry));
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        $subnet = self::canonicalIp(trim($subnet));
        if (!self::isValidIp($subnet) || !ctype_digit($bits)) {
            return false;
        }
        $prefix = (int) $bits;
        $bin = inet_pton($subnet);
        if ($bin === false) {
            return false;
        }
        $max = strlen($bin) * 8;

        return $prefix >= 0 && $prefix <= $max;
    }

    private static function sendDenied(string $format): never
    {
        http_response_code(403);
        $plain = 'forbidden: extra gate required';
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $plain]);
            exit;
        }
        if ($format === 'plain') {
            header('Content-Type: text/plain; charset=utf-8');
            echo $plain;
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Forbidden</title></head><body><p>Forbidden. This hostname'
            . ' requires an IP allowlist or a verified client certificate.</p>'
            . '</body></html>';
        exit;
    }

    private static function hostIsLoopbackName(string $host): bool
    {
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === '::ffff:127.0.0.1';
    }

    private static function ipIsLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1' || $ip === '::ffff:127.0.0.1';
    }

    private static function ipIsPrivateOverlay(string $ip): bool
    {
        return self::ipInNetwork($ip, '100.64.0.0/10')
            || self::ipInNetwork($ip, 'fd00::/8');
    }

    private static function canonicalIp(string $ip): string
    {
        $ip = strtolower(trim($ip));
        if (str_starts_with($ip, '::ffff:')) {
            $v4 = substr($ip, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $v4;
            }
        }

        return $ip;
    }

    private static function stripIpBracketsAndPort(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '[')) {
            $end = strpos($raw, ']');

            return $end === false ? $raw : substr($raw, 1, $end - 1);
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $raw, $m) === 1) {
            return $m[1];
        }

        return $raw;
    }

    private static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
