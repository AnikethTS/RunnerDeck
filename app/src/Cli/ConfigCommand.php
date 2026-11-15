<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

use RunnerDeck\Configuration\Settings;
use RunnerDeck\Http\Action\Administration\SaveSettingsAction;

final class ConfigCommand
{
    private const PREFIX = 'RUNNERDECK_';
    /** Never listed or changed here. */
    private const HIDDEN = ['RUNNERDECK_AUTH_TOTP_SECRET'];
    /** Changed with `runnerdeck setup`, because they depend on each other. */
    private const SCOPE_KEYS = ['scope', 'org', 'repo'];
    private const FLAGS = [
        'check-updates', 'auto-restart', 'scale-to-queue', 'auto-clear-work',
        'syslog', 'bundle-snapshot', 'bundle-snapshot-secrets', 'require-mtls',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];
        foreach (Settings::keys() as $key) {
            if (!in_array($key, self::HIDDEN, true)) {
                $names[] = self::friendly($key);
            }
        }
        return $names;
    }

    public static function friendly(string $envKey): string
    {
        return strtolower(str_replace('_', '-', substr($envKey, strlen(self::PREFIX))));
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $out = [];
        foreach (Settings::load() as $key => $value) {
            if (!in_array($key, self::HIDDEN, true) && str_starts_with($key, self::PREFIX)) {
                $out[self::friendly($key)] = $value;
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array{ok: bool, message: string} */
    public static function set(string $name, string $value): array
    {
        if (!in_array($name, self::names(), true)) {
            return ['ok' => false, 'message' => "unknown setting: {$name} (see `runnerdeck config list --all`)"];
        }
        if (in_array($name, self::SCOPE_KEYS, true)) {
            return ['ok' => false, 'message' => "use `runnerdeck setup --{$name} ...` to change the {$name}"];
        }
        if (!isset(Settings::load()['RUNNERDECK_SCOPE'])) {
            return ['ok' => false, 'message' => 'RunnerDeck is not set up yet. Run: runnerdeck setup'];
        }
        if (in_array($name, self::FLAGS, true)) {
            $parsed = self::flag($value);
            if ($parsed === null) {
                return ['ok' => false, 'message' => "{$name} takes on or off"];
            }
            $value = $parsed;
        }
        return self::save([self::postKey($name) => $value]);
    }

    /** @return array{ok: bool, message: string} */
    public static function unset(string $name): array
    {
        return self::set($name, '');
    }

    /** @return array{ok: bool, message: string} */
    public static function setup(string $scope, string $target): array
    {
        $scope = strtolower($scope);
        if ($scope !== 'org' && $scope !== 'repo') {
            return ['ok' => false, 'message' => "scope must be 'org' or 'repo'"];
        }
        $changes = ['scope' => $scope, $scope => $target];
        $result = self::save($changes);
        return $result['ok']
            ? ['ok' => true, 'message' => "Saved. Scope: {$scope} ({$target})."]
            : $result;
    }

    /**
     * @param array<string, string> $changes
     * @return array{ok: bool, message: string}
     */
    private static function save(array $changes): array
    {
        $post = [];
        foreach (Settings::load() as $key => $value) {
            if (!in_array($key, self::HIDDEN, true)) {
                $post[strtolower(substr($key, strlen(self::PREFIX)))] = $value;
            }
        }
        foreach ($changes as $name => $value) {
            $post[self::postKey($name)] = $value;
        }
        return SaveSettingsAction::save($post);
    }

    private static function postKey(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    private static function flag(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            '1', 'on', 'true', 'yes' => '1',
            '', '0', 'off', 'false', 'no' => '',
            default => null,
        };
    }
}
