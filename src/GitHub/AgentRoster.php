<?php

declare(strict_types=1);

namespace RunnerDeck\GitHub;

use RunnerDeck\Core\Settings;

final class AgentRoster
{
    private const FILE = 'agent_roster.json';

    public static function remember(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $names = self::names();
        if (in_array($name, $names, true)) {
            return;
        }
        $names[] = $name;
        self::write($names);
    }

    public static function forget(string $name): void
    {
        $name = trim($name);
        $kept = array_values(array_filter(
            self::names(),
            static fn (string $item): bool => $item !== $name
        ));
        self::write($kept);
    }

    /** @return list<string> */
    public static function names(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * GitHub runners this host once registered that have no local slot now.
     *
     * @param array<string, array{id: int, status: string, busy: bool, labels: string[], os: string}> $ghRunners
     * @param list<string> $localNames
     * @return list<array{name: string, id: int, status: string, busy: bool}>
     */
    public static function orphans(array $ghRunners, array $localNames): array
    {
        $local = array_fill_keys($localNames, true);
        $out = [];
        foreach (self::names() as $name) {
            if (isset($local[$name]) || !isset($ghRunners[$name])) {
                continue;
            }
            $row = $ghRunners[$name];
            $out[] = [
                'name' => $name,
                'id' => $row['id'],
                'status' => $row['status'],
                'busy' => $row['busy'],
            ];
        }
        return $out;
    }

    /** @param list<string> $names */
    public static function replace(array $names): void
    {
        $out = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '') {
                $out[] = $name;
            }
        }
        self::write(array_values(array_unique($out)));
    }

    /** @param list<string> $names */
    private static function write(array $names): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = self::path() . '.tmp';
        file_put_contents($tmp, json_encode($names, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, self::path());
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }
}
