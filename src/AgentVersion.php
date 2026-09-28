<?php

declare(strict_types=1);

namespace RunnerDeck;

final class AgentVersion
{
    private const VERSION_IN_KEY = '/^Runner\.Listener\/(\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.]+)?)/';
    private const VERSION_IN_TEXT = '/Runner\.Listener\/(\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.]+)?)/';

    /** Local bits only — never GitHub. `.runner` if present, else Listener deps. */
    public static function read(string $dir): ?string
    {
        $fromRunner = self::fromJsonFile($dir . '/.runner', ['agentVersion', 'agent_version', 'version']);
        if ($fromRunner !== null) {
            return $fromRunner;
        }

        return self::fromListenerDeps($dir . '/bin/Runner.Listener.deps.json');
    }

    /**
     * @param list<string> $keys
     */
    private static function fromJsonFile(string $path, array $keys): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path)) ?? '';
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }
        foreach ($keys as $key) {
            $value = $json[$key] ?? null;
            $normalized = is_string($value) ? self::normalize($value) : null;
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return null;
    }

    private static function fromListenerDeps(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $targets = $json['targets'] ?? null;
            if (is_array($targets)) {
                foreach ($targets as $framework) {
                    if (!is_array($framework)) {
                        continue;
                    }
                    foreach (array_keys($framework) as $key) {
                        if (!is_string($key)) {
                            continue;
                        }
                        if (preg_match(self::VERSION_IN_KEY, $key, $m)) {
                            return $m[1];
                        }
                    }
                }
            }
        }
        if (preg_match(self::VERSION_IN_TEXT, $raw, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function normalize(string $raw): ?string
    {
        $value = ltrim(trim($raw), 'vV');
        if ($value === '' || !preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.]+)?$/', $value)) {
            return null;
        }
        return $value;
    }
}
