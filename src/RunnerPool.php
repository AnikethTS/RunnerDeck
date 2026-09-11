<?php

final class RunnerInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $dir,
        public readonly bool $configured,
        public readonly ?string $agentName,
        public readonly bool $localRunning,
        public readonly ?int $pid,
        public readonly array $logTail,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'configured' => $this->configured,
            'agent_name' => $this->agentName,
            'local_running' => $this->localRunning,
            'pid' => $this->pid,
            'log_tail' => $this->logTail,
        ];
    }
}

final class RunnerPool
{
    private const ID_PATTERN = '/^runner-(base|[0-9]+)$/';

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
            if ($a === 'runner-base') return -1;
            if ($b === 'runner-base') return 1;
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
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($runnerFile) ?: '');
            $json = json_decode($raw, true);
            $agentName = $json['agentName'] ?? null;
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

        return new RunnerInfo(
            id: $id,
            dir: $dir,
            configured: $configured,
            agentName: $agentName ?? self::agentNameFor($id),
            localRunning: $running,
            pid: $pid,
            logTail: self::tailLog("$dir/runner.log", $logLines),
        );
    }

    /** @return array<string, int> runner dir => pid, independent of any pidfile */
    public static function liveListenersByDir(): array
    {
        static $cache = null;
        static $cachedAt = 0;
        if ($cache !== null && (microtime(true) - $cachedAt) < 2.0) {
            return $cache;
        }

        $map = [];
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $procDir) {
            $exe = @readlink("$procDir/exe");
            if ($exe !== false && str_ends_with($exe, '/bin/Runner.Listener')) {
                $pid = (int) basename($procDir);
                $map[dirname($exe, 2)] = $pid;
            }
        }

        $cache = $map;
        $cachedAt = microtime(true);
        return $map;
    }

    /** @return array{0: bool, 1: ?int} [running, pid] */
    public static function checkProcess(string $pidFile): array
    {
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
        $cmdline = @file_get_contents("/proc/$pid/cmdline");
        if ($cmdline !== false && stripos($cmdline, 'Runner.Listener') === false) {
            return [false, $pid];
        }
        return [true, $pid];
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
