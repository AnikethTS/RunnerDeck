<?php

declare(strict_types=1);

namespace RunnerDeck;

final class Config
{
    public static function bootstrapEnv(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        Settings::applyToEnv();

        $envFile = dirname(__DIR__) . '/.env';
        if (!is_file($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            putenv($key . '=' . trim($value));
        }
    }

    public static function org(): string
    {
        $org = getenv('RUNNERDECK_ORG');
        if (!$org) {
            throw new \RuntimeException('RUNNERDECK_ORG is not set — see README');
        }
        return $org;
    }

    /** @return string "owner/repo" — only used when scope() is 'repo' */
    public static function repo(): string
    {
        $repo = getenv('RUNNERDECK_REPO');
        if (!$repo) {
            throw new \RuntimeException('RUNNERDECK_REPO is not set — see README');
        }
        return $repo;
    }

    /**
     * @return 'org'|'repo' whether runners are managed at the org or
     * single-repo level. There is no such thing as a personal-account-level
     * self-hosted runner in GitHub's API — 'repo' is the real alternative
     * for anyone who isn't an org admin.
     */
    public static function scope(): string
    {
        $scope = getenv('RUNNERDECK_SCOPE') ?: 'org';
        if ($scope !== 'org' && $scope !== 'repo') {
            throw new \RuntimeException("RUNNERDECK_SCOPE must be 'org' or 'repo', got '{$scope}'");
        }
        return $scope;
    }

    /** True once the active scope's required identifier (org or repo) is actually set — never throws. */
    public static function isConfigured(): bool
    {
        try {
            $scope = self::scope();
        } catch (\RuntimeException) {
            return false;
        }
        return $scope === 'repo' ? (bool) getenv('RUNNERDECK_REPO') : (bool) getenv('RUNNERDECK_ORG');
    }

    public static function label(): string
    {
        return getenv('RUNNERDECK_LABEL') ?: 'self-hosted-runnerdeck';
    }

    /**
     * Extra GitHub runner labels from RUNNERDECK_EXTRA_LABELS. Applied on
     * add/rename (config.sh). Invalid env is ignored so a typo cannot
     * block the shared label.
     *
     * @return list<string>
     */
    public static function extraLabels(): array
    {
        return self::parseLabels((string) (getenv('RUNNERDECK_EXTRA_LABELS') ?: '')) ?? [];
    }

    /** Comma-separated pool labels passed to config.sh when the slot has none of its own. */
    public static function provisionLabels(): string
    {
        return self::provisionLabelsFor('');
    }

    /**
     * @return list<string>|null null when the raw value is invalid
     */
    public static function parseLabels(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (strlen($part) > 50 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $part) !== 1) {
                return null;
            }
            $parts[] = $part;
        }
        $parts = array_values(array_unique($parts));
        if (count($parts) > 12) {
            return null;
        }
        return $parts;
    }

    public static function checkUpdatesEnabled(): bool
    {
        return getenv('RUNNERDECK_CHECK_UPDATES') === '1';
    }

    public static function autoRestartEnabled(): bool
    {
        return getenv('RUNNERDECK_AUTO_RESTART') === '1';
    }

    /** Start stopped idle slots when GitHub jobs are waiting on this label. */
    public static function scaleToQueueEnabled(): bool
    {
        return getenv('RUNNERDECK_SCALE_TO_QUEUE') === '1';
    }

    /**
     * Max locally running slots (1–30). Null means no cap beyond the
     * 30-slot pool size. Starts (including scale-to-queue) refuse above this.
     */
    public static function poolCap(): ?int
    {
        $raw = getenv('RUNNERDECK_POOL_CAP');
        if ($raw === false || $raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return null;
        }
        return min(30, $n);
    }

    /**
     * GiB that must stay free on the pool filesystem before a start
     * (0 = off, default 1). Unknown disk readings fail open.
     */
    public static function minFreeDiskGib(): int
    {
        return self::optionalBoundEnv('RUNNERDECK_MIN_FREE_DISK_GIB', 1, 1000, 1);
    }

    /**
     * MiB of MemAvailable that must remain before a start
     * (0 = off, default 256). Unreadable RAM fails open (typical on macOS).
     */
    public static function minFreeRamMib(): int
    {
        return self::optionalBoundEnv('RUNNERDECK_MIN_FREE_RAM_MIB', 1, 1048576, 256);
    }

    /**
     * Empty/unset uses $default. "0" disables. Other non-digits use $default.
     */
    private static function optionalBoundEnv(string $key, int $minOn, int $max, int $default): int
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            return $default;
        }
        if (!ctype_digit($raw)) {
            return $default;
        }
        $n = (int) $raw;
        if ($n === 0) {
            return 0;
        }
        if ($n < $minOn) {
            return $default;
        }
        return min($max, $n);
    }

    /**
     * After this many idle minutes, drain-stop a running slot (1–1440).
     * Null means idle-stop is off. Skips busy and Pause-AR slots, and
     * does nothing while jobs are queued for this label.
     */
    public static function idleStopMinutes(): ?int
    {
        $raw = getenv('RUNNERDECK_IDLE_STOP_MINUTES');
        if ($raw === false || $raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return null;
        }
        return min(1440, $n);
    }

    /**
     * Fire the crash webhook when a queued job has waited this many minutes
     * (1–1440). Null means the alert is off.
     */
    public static function queueWaitMinutes(): ?int
    {
        $raw = getenv('RUNNERDECK_QUEUE_WAIT_MINUTES');
        if ($raw === false || $raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return null;
        }
        return min(1440, $n);
    }

    /** Comma-separated labels for config.sh, including per-slot extras. */
    public static function provisionLabelsFor(string $dir): string
    {
        $labels = array_values(array_unique(array_merge(
            [self::label()],
            self::extraLabels(),
            SlotFlags::extraLabels($dir)
        )));
        return implode(',', $labels);
    }

    /** Seconds to wait for GitHub busy=false before drain-stop gives up (1–3600, default 600). */
    public static function drainTimeoutSeconds(): int
    {
        $raw = getenv('RUNNERDECK_DRAIN_TIMEOUT');
        if ($raw === false || $raw === '' || !ctype_digit($raw)) {
            return 600;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return 600;
        }
        return min(3600, $n);
    }

    /** @return int|null GiB threshold for the disk webhook, or null if unset */
    public static function diskWebhookThresholdGb(): ?int
    {
        $raw = getenv('RUNNERDECK_DISK_WEBHOOK_THRESHOLD');
        if ($raw === false || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw < 1) {
            return null;
        }
        return (int) $raw;
    }

    /** @return string|null a URL to POST a crash-loop notification to, or null if unset */
    public static function crashWebhookUrl(): ?string
    {
        $url = getenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        return $url !== false && $url !== '' ? $url : null;
    }

    /** @return int|null 7-day crash count that also fires the webhook, or null if unset */
    public static function crashWebhookThreshold(): ?int
    {
        $raw = getenv('RUNNERDECK_CRASH_WEBHOOK_THRESHOLD');
        if ($raw === false || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw < 1) {
            return null;
        }
        return (int) $raw;
    }

    /** @return string|null the base32 TOTP secret shared with an authenticator app, or null if login is off */
    public static function authTotpSecret(): ?string
    {
        $secret = getenv('RUNNERDECK_AUTH_TOTP_SECRET');
        return $secret !== false && $secret !== '' ? $secret : null;
    }

    public static function authEnabled(): bool
    {
        return self::authTotpSecret() !== null;
    }

    /** @return int|null idle minutes before a TOTP session expires, or null if unset */
    public static function sessionIdleMinutes(): ?int
    {
        $raw = getenv('RUNNERDECK_SESSION_IDLE_MINUTES');
        if ($raw === false || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw < 1) {
            return null;
        }
        return min(1440, (int) $raw);
    }

    /**
     * Honor X-Forwarded-Proto from a reverse proxy that is not on loopback
     * (Caddy/nginx on another host, Docker published ports, Cloudflare).
     * Loopback REMOTE_ADDR already trusts that header without this flag.
     */
    public static function trustProxy(): bool
    {
        return getenv('RUNNERDECK_TRUST_PROXY') === '1';
    }

    public static function version(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }
        $raw = @file_get_contents(dirname(__DIR__) . '/VERSION');
        return $version = $raw !== false ? trim($raw) : '0.0.0';
    }

    public static function poolDir(): string
    {
        $override = getenv('RUNNERDECK_POOL_DIR');
        if ($override) {
            return rtrim($override, '/');
        }
        return dirname(__DIR__, 2) . '/runners';
    }

    public static function ghBinary(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $override = getenv('RUNNERDECK_GH_BIN');
        if ($override && is_executable($override)) {
            return $resolved = $override;
        }

        $candidates = [
            getenv('HOME') . '/.local/bin/gh',
            '/usr/local/bin/gh',
            '/usr/bin/gh',
            '/opt/homebrew/bin/gh',
        ];
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $resolved = $path;
            }
        }

        return $resolved = 'gh';
    }

    public static function ghConfigDir(): ?string
    {
        static $resolved = false;
        if ($resolved !== false) {
            return $resolved;
        }

        $existing = getenv('GH_CONFIG_DIR');
        if ($existing && is_file("$existing/hosts.yml")) {
            return $resolved = null;
        }

        $default = getenv('HOME') . '/.config/gh';
        return $resolved = is_file("$default/hosts.yml") ? $default : null;
    }
}
