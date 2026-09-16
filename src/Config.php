<?php

declare(strict_types=1);

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
            throw new RuntimeException('RUNNERDECK_ORG is not set — see README');
        }
        return $org;
    }

    /** @return string "owner/repo" — only used when scope() is 'repo' */
    public static function repo(): string
    {
        $repo = getenv('RUNNERDECK_REPO');
        if (!$repo) {
            throw new RuntimeException('RUNNERDECK_REPO is not set — see README');
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
            throw new RuntimeException("RUNNERDECK_SCOPE must be 'org' or 'repo', got '{$scope}'");
        }
        return $scope;
    }

    /** True once the active scope's required identifier (org or repo) is actually set — never throws. */
    public static function isConfigured(): bool
    {
        try {
            $scope = self::scope();
        } catch (RuntimeException) {
            return false;
        }
        return $scope === 'repo' ? (bool) getenv('RUNNERDECK_REPO') : (bool) getenv('RUNNERDECK_ORG');
    }

    public static function label(): string
    {
        return getenv('RUNNERDECK_LABEL') ?: 'self-hosted-runnerdeck';
    }

    public static function checkUpdatesEnabled(): bool
    {
        return getenv('RUNNERDECK_CHECK_UPDATES') === '1';
    }

    public static function autoRestartEnabled(): bool
    {
        return getenv('RUNNERDECK_AUTO_RESTART') === '1';
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
