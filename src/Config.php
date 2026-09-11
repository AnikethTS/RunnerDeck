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
        $org = getenv('RUNNER_DASHBOARD_ORG');
        if (!$org) {
            throw new RuntimeException('RUNNER_DASHBOARD_ORG is not set — see README');
        }
        return $org;
    }

    public static function label(): string
    {
        return getenv('RUNNER_DASHBOARD_LABEL') ?: 'self-hosted-dashboard';
    }

    public static function poolDir(): string
    {
        $override = getenv('RUNNER_DASHBOARD_POOL_DIR');
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

        $override = getenv('RUNNER_DASHBOARD_GH_BIN');
        if ($override && is_executable($override)) {
            return $resolved = $override;
        }

        foreach ([
            getenv('HOME') . '/.local/bin/gh',
            '/usr/local/bin/gh',
            '/usr/bin/gh',
            '/opt/homebrew/bin/gh',
        ] as $path) {
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
