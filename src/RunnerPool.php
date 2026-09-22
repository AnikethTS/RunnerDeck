<?php

declare(strict_types=1);

namespace RunnerDeck;

final class RunnerPool
{
    private const ID_PATTERN = '/^runner-(base|[0-9]+)$/';

    /** @var array<string, int>|null */
    private static ?array $liveListenersFake = null;

    /** @var (callable(string): array{0: bool, 1: ?int})|null */
    private static $checkProcessFake = null;

    /** @var array<string, int>|null */
    private static $listenersCache = null;

    private static float $listenersCachedAt = 0;

    /** @param array<string, int>|null $map */
    public static function fakeLiveListeners(?array $map): void
    {
        self::$liveListenersFake = $map;
        self::$listenersCache = null;
        self::$listenersCachedAt = 0;
    }

    /**
     * Test seam: override pidfile liveness. Pass null to restore.
     *
     * @param (callable(string): array{0: bool, 1: ?int})|null $handler
     */
    public static function fakeCheckProcess(?callable $handler): void
    {
        self::$checkProcessFake = $handler;
    }

    /** @return array<string, RunnerInfo> keyed by id, base first then numeric order */
    public static function discover(int $logLines = 15): array
    {
        $poolDir = Config::poolDir();
        $ids = [];
        foreach (glob("$poolDir/runner-*", GLOB_ONLYDIR) ?: [] as $path) {
            $id = basename($path);
            if (preg_match(self::ID_PATTERN, $id)) {
                $ids[] = $id;
            }
        }
        usort($ids, function (string $a, string $b) {
            if ($a === 'runner-base') {
                return -1;
            }
            if ($b === 'runner-base') {
                return 1;
            }
            return (int) substr($a, 7) <=> (int) substr($b, 7);
        });

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = self::inspect($poolDir, $id, $logLines);
        }
        return $result;
    }

    public static function isKnownId(string $id): bool
    {
        return (bool) preg_match(self::ID_PATTERN, $id) && is_dir(Config::poolDir() . "/$id");
    }

    public static function dirFor(string $id): string
    {
        return Config::poolDir() . "/$id";
    }

    public static function agentNameFor(string $id): string
    {
        return $id === 'runner-base' ? Config::label() : Config::label() . '-' . substr($id, 7);
    }

    private static function inspect(string $poolDir, string $id, int $logLines): RunnerInfo
    {
        $dir = "$poolDir/$id";
        $runnerFile = "$dir/.runner";
        $configured = is_file($runnerFile);
        $agentName = null;

        if ($configured) {
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($runnerFile) ?: '') ?? '';
            $json = json_decode($raw, true);
            $fromJson = is_array($json) ? ($json['agentName'] ?? null) : null;
            $agentName = is_string($fromJson) ? $fromJson : null;
        }

        [$running, $pid] = self::checkProcess("$dir/runner.pid");

        if (!$running) {
            $found = self::liveListenersByDir()[$dir] ?? null;
            if ($found !== null) {
                $running = true;
                $pid = $found;
                @file_put_contents("$dir/runner.pid", (string) $found);
            }
        }

        $stats = ($running && $pid !== null) ? (self::allProcessStats()[$pid] ?? null) : null;

        return new RunnerInfo(
            id: $id,
            dir: $dir,
            configured: $configured,
            agentName: $agentName ?? self::agentNameFor($id),
            localRunning: $running,
            pid: $pid,
            logTail: self::tailLog("$dir/runner.log", $logLines),
            cpuPercent: $stats['cpu_percent'] ?? null,
            rssKb: $stats['rss_kb'] ?? null,
            uptimeSeconds: $stats['uptime_seconds'] ?? null,
        );
    }

    /** @return string the next unused runner-base|runner-N slot, in allocation order */
    public static function nextAvailableId(): string
    {
        $poolDir = Config::poolDir();
        $maxNum = 0;
        $hasBase = false;
        foreach (glob("$poolDir/runner-*", GLOB_ONLYDIR) ?: [] as $path) {
            $id = basename($path);
            if (!preg_match(self::ID_PATTERN, $id)) {
                continue;
            }
            if ($id === 'runner-base') {
                $hasBase = true;
            } else {
                $maxNum = max($maxNum, (int) substr($id, 7));
            }
        }
        return $hasBase ? 'runner-' . ($maxNum + 1) : 'runner-base';
    }

    /**
     * @return array<int, array{cpu_percent: float, rss_kb: int, uptime_seconds: int}>
     * pid => live usage, cached briefly
     */
    public static function allProcessStats(): array
    {
        static $cache = null;
        static $cachedAt = 0;
        if ($cache !== null && (microtime(true) - $cachedAt) < 2.0) {
            return $cache;
        }

        $result = Shell::exec(['ps', '-eo', 'pid=,%cpu=,rss=,etimes='], 5);
        $map = [];
        if ($result['code'] === 0) {
            foreach (explode("\n", $result['stdout']) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (!is_array($parts) || count($parts) !== 4) {
                    continue;
                }
                [$pid, $cpu, $rss, $etimes] = $parts;
                $map[(int) $pid] = [
                    'cpu_percent' => (float) $cpu,
                    'rss_kb' => (int) $rss,
                    'uptime_seconds' => (int) $etimes,
                ];
            }
        }

        $cache = $map;
        $cachedAt = microtime(true);
        return $map;
    }

    /** @return array<string, int> runner dir => pid, independent of any pidfile */
    public static function liveListenersByDir(): array
    {
        if (self::$liveListenersFake !== null) {
            return self::$liveListenersFake;
        }

        if (self::$listenersCache !== null && (microtime(true) - self::$listenersCachedAt) < 2.0) {
            return self::$listenersCache;
        }

        $map = PHP_OS_FAMILY === 'Darwin' ? self::liveListenersByDirDarwin() : self::liveListenersByDirLinux();

        self::$listenersCache = $map;
        self::$listenersCachedAt = microtime(true);
        return $map;
    }

    /** @return array<string, int> runner dir => pid, via /proc (Linux only) */
    private static function liveListenersByDirLinux(): array
    {
        $map = [];
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $procDir) {
            $exe = @readlink("$procDir/exe");
            if ($exe !== false && str_ends_with($exe, '/bin/Runner.Listener')) {
                $pid = (int) basename($procDir);
                $map[dirname($exe, 2)] = $pid;
            }
        }
        return $map;
    }

    /**
     * @return array<string, int> runner dir => pid, via `ps` + `lsof` (macOS
     * has no /proc; Runner.Listener is invoked with a relative path, so its
     * cwd — which is the runner dir itself — is the only reliable handle).
     */
    private static function liveListenersByDirDarwin(): array
    {
        $ps = Shell::exec(['ps', '-awwxo', 'pid=,command='], 5);
        if ($ps['code'] !== 0) {
            return [];
        }

        $map = [];
        foreach (explode("\n", $ps['stdout']) as $line) {
            $line = trim($line);
            if ($line === '' || !preg_match('/^(\d+)\s+(.*Runner\.Listener.*)$/', $line, $m)) {
                continue;
            }
            $pid = (int) $m[1];
            $lsof = Shell::exec(['lsof', '-p', (string) $pid, '-a', '-d', 'cwd', '-Fn'], 5);
            if ($lsof['code'] !== 0) {
                continue;
            }
            foreach (explode("\n", $lsof['stdout']) as $lsofLine) {
                if (str_starts_with($lsofLine, 'n') && strlen($lsofLine) > 1) {
                    $map[substr($lsofLine, 1)] = $pid;
                    break;
                }
            }
        }
        return $map;
    }

    /** @return array{0: bool, 1: ?int} [running, pid] */
    public static function checkProcess(string $pidFile): array
    {
        if (self::$checkProcessFake !== null) {
            return (self::$checkProcessFake)($pidFile);
        }

        if (!is_file($pidFile)) {
            return [false, null];
        }
        $pid = (int) trim(file_get_contents($pidFile) ?: '0');
        if ($pid <= 0) {
            return [false, null];
        }
        if (!posix_kill($pid, 0)) {
            return [false, $pid];
        }
        $cmd = self::processCommand($pid);
        if ($cmd !== null && stripos($cmd, 'Runner.Listener') === false) {
            return [false, $pid];
        }
        return [true, $pid];
    }

    /** Best-effort command line for a pid, so a reused pid isn't mistaken for a live runner. */
    private static function processCommand(int $pid): ?string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $result = Shell::exec(['ps', '-p', (string) $pid, '-o', 'command='], 3);
            return $result['code'] === 0 && trim($result['stdout']) !== '' ? trim($result['stdout']) : null;
        }
        $cmdline = @file_get_contents("/proc/$pid/cmdline");
        return $cmdline !== false ? $cmdline : null;
    }

    public static function tailLog(string $path, int $lines): array
    {
        if (!is_file($path) || $lines <= 0) {
            return [];
        }
        $size = filesize($path);
        if ($size === 0) {
            return [];
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }

        $chunkSize = 8192;
        $pos = $size;
        $data = '';
        while ($pos > 0 && substr_count($data, "\n") <= $lines) {
            $read = min($chunkSize, $pos);
            $pos -= $read;
            fseek($fh, $pos);
            $data = fread($fh, $read) . $data;
        }
        fclose($fh);

        $allLines = explode("\n", rtrim($data, "\n"));
        return array_slice($allLines, -$lines);
    }
}
