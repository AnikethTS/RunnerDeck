<?php

declare(strict_types=1);

namespace RunnerDeck;

final class AgentTarget
{
    private const FILE = 'agent_target.json';
    private const TTL = 3600;

    /** @var (callable(): ?string)|null */
    private static $fake = null;

    /** Tests only. */
    public static function fake(?callable $fn): void
    {
        self::$fake = $fn;
    }

    /**
     * GitHub's current runner package version for this OS/arch. Cached 1h.
     * Fail-open: unknown is null, never blocks starts.
     */
    public static function latest(): ?string
    {
        if (self::$fake !== null) {
            return (self::$fake)();
        }
        $cached = self::read();
        $fetchedAt = (int) ($cached['fetched_at'] ?? 0);
        $version = isset($cached['version']) && is_string($cached['version'])
            ? $cached['version']
            : null;
        if ($version !== null && $fetchedAt > 0 && (time() - $fetchedAt) < self::TTL) {
            return $version;
        }
        try {
            $download = Provisioner::pickDownloadForHost();
            $fresh = Provisioner::versionFromDownload($download);
        } catch (\RuntimeException) {
            return $version;
        }
        if ($fresh !== null) {
            self::write(['version' => $fresh, 'fetched_at' => time()]);
            return $fresh;
        }
        return $version;
    }

    /**
     * @param array<int, array<string, mixed>> $runners
     * @return array{
     *     runners: array<int, array<string, mixed>>,
     *     latest: ?string,
     *     behind_count: int
     * }
     */
    public static function annotate(array $runners): array
    {
        $latest = self::latest();
        $behind = 0;
        foreach ($runners as $i => $r) {
            $local = $r['agent_version'] ?? null;
            $isBehind = is_string($latest)
                && is_string($local)
                && $local !== ''
                && version_compare($local, $latest, '<');
            $runners[$i]['agent_behind'] = $isBehind;
            if ($isBehind) {
                $behind++;
            }
        }
        return ['runners' => $runners, 'latest' => $latest, 'behind_count' => $behind];
    }

    /** @return array<string, mixed> */
    private static function read(): array
    {
        $path = Settings::storageDir() . '/' . self::FILE;
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array{version: string, fetched_at: int} $state */
    private static function write(array $state): void
    {
        $path = Settings::storageDir() . '/' . self::FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }
}
