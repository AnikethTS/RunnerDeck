<?php

declare(strict_types=1);

namespace RunnerDeck\Core;

final class HostingWarnings
{
    private static ?string $sapiFake = null;

    /** Test seam. Pass null to restore PHP_SAPI. */
    public static function fakeSapi(?string $sapi): void
    {
        self::$sapiFake = $sapi;
    }

    /**
     * Quiet on loopback HTTP (localhost / 127.0.0.1). Speaks up for a
     * TLS hostname, a LAN/public Host, php -S, or TRUST_PROXY.
     *
     * @return list<array{level: 'warning'|'info', text: string}>
     */
    public static function messages(): array
    {
        if (!self::isHostedRequest()) {
            return [];
        }

        $out = [];
        if (!Session::requestIsHttps()) {
            $out[] = [
                'level' => 'warning',
                'text' => 'This hostname is not HTTPS. Login codes and session'
                    . ' cookies travel in the clear. Put TLS in front'
                    . ' (docs/hosting.md).',
            ];
        }
        if (ClientGate::blocked()) {
            $out[] = [
                'level' => 'warning',
                'text' => 'TOTP is the only gate this process can see. Prefer'
                    . ' Tailscale, or an IP allowlist / mTLS on 443'
                    . ' (docs/hosting.md). A VPS compromise is an org/repo'
                    . ' compromise.',
            ];
        }
        if (self::sapi() === 'cli-server') {
            $out[] = [
                'level' => 'info',
                'text' => 'This request is served by php -S (four workers).'
                    . ' Prefer php-fpm for a TLS hostname (docs/hosting.md).',
            ];
        }
        if (Config::trustProxy() && !Session::remoteIsLoopback()) {
            $out[] = [
                'level' => 'warning',
                'text' => 'RUNNERDECK_TRUST_PROXY is on. Anyone who can reach'
                    . ' PHP can forge X-Forwarded-Proto. Keep PHP on loopback.',
            ];
        }
        return $out;
    }

    public static function html(): string
    {
        $items = self::messages();
        if ($items === []) {
            return '';
        }
        $html = '<div id="hosting-warnings">' . "\n";
        foreach ($items as $row) {
            $cls = $row['level'] === 'info' ? 'banner banner-info' : 'banner banner-warning';
            $html .= '  <div class="' . $cls . '" role="status"><div class="page-shell">'
                . htmlspecialchars($row['text'], ENT_QUOTES, 'UTF-8')
                . "</div></div>\n";
        }
        return $html . "</div>\n";
    }

    /**
     * Always-on Settings facts for this request — not gated on hosted Host.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function facts(): array
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $bind = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $rows = [
            ['label' => 'Host', 'value' => $host !== '' ? $host : '(unknown)'],
        ];
        if ($bind !== '') {
            $rows[] = ['label' => 'Bind', 'value' => $bind];
        }
        $rows[] = ['label' => 'HTTPS', 'value' => Session::requestIsHttps() ? 'Yes' : 'No'];
        $rows[] = ['label' => 'Server', 'value' => self::serverLabel()];
        $rows[] = ['label' => 'TOTP', 'value' => Auth::isEnabled() ? 'On' : 'Off'];
        $rows[] = [
            'label' => 'TRUST_PROXY',
            'value' => Config::trustProxy() ? 'On' : 'Off',
        ];
        $rows[] = ['label' => 'Extra gate', 'value' => ClientGate::extraGateFact()];
        return $rows;
    }

    public static function settingsCardHtml(): string
    {
        $html = '<section class="settings-card" id="this-host" aria-labelledby="this-host-heading">' . "\n"
            . '  <h3 id="this-host-heading">This host</h3>' . "\n"
            . '  <p class="settings-hint">How this request reached PHP. Banner warnings'
            . ' fire when Host is not localhost or the request is HTTPS.</p>' . "\n"
            . '  <dl class="settings-facts">' . "\n";
        foreach (self::facts() as $row) {
            $html .= '    <dt>' . htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') . '</dt>' . "\n"
                . '    <dd>' . htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8') . '</dd>' . "\n";
        }
        return $html
            . "  </dl>\n"
            . '  <p class="settings-hint"><a href="https://github.com/AnikethTS/RunnerDeck/blob/main/docs/hosting.md">'
            . 'Hosting docs</a></p>' . "\n"
            . "</section>\n";
    }

    public static function isHostedRequest(): bool
    {
        if (Session::requestIsHttps()) {
            return true;
        }
        $host = self::requestHost();
        return $host !== '' && !self::hostIsLoopbackName($host);
    }

    public static function requestHost(): string
    {
        $raw = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, '[')) {
            $end = strpos($raw, ']');
            return $end === false ? $raw : substr($raw, 1, $end - 1);
        }
        $colon = strrpos($raw, ':');
        if ($colon !== false && ctype_digit(substr($raw, $colon + 1))) {
            return substr($raw, 0, $colon);
        }
        return $raw;
    }

    private static function hostIsLoopbackName(string $host): bool
    {
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === '::ffff:127.0.0.1';
    }

    public static function serverLabel(): string
    {
        $sapi = self::sapi();
        return match ($sapi) {
            'cli-server' => 'php -S',
            'fpm-fcgi', 'cgi-fcgi' => 'php-fpm',
            '' => '(unknown)',
            default => $sapi,
        };
    }

    private static function sapi(): string
    {
        return self::$sapiFake ?? PHP_SAPI;
    }
}
