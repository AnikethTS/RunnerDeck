<?php

declare(strict_types=1);

namespace RunnerDeck;

// Whole-machine CPU/RAM usage, distinct from Dashboard's per-runner stats.
// Reuses RunnerPool::allProcessStats() (already a system-wide `ps` scan,
// just filtered to runner pids elsewhere) rather than a second shell call.
final class SystemStats
{
    /** @return array{cpu_percent: ?float, mem_used_kb: int, mem_total_kb: ?int, mem_percent: ?float, cpu_cores: int} */
    public static function snapshot(): array
    {
        $processes = RunnerPool::allProcessStats();
        $cpuSum = array_sum(array_column($processes, 'cpu_percent'));
        $memUsedKb = (int) array_sum(array_column($processes, 'rss_kb'));

        $cores = self::cpuCores();
        $totalMemKb = self::totalMemKb();

        return [
            'cpu_percent' => $cores > 0 ? min(100.0, $cpuSum / $cores) : null,
            'cpu_cores' => $cores,
            'mem_used_kb' => $memUsedKb,
            'mem_total_kb' => $totalMemKb,
            'mem_percent' => $totalMemKb ? min(100.0, ($memUsedKb / $totalMemKb) * 100) : null,
        ];
    }

    private static function cpuCores(): int
    {
        static $cores = null;
        if ($cores !== null) {
            return $cores;
        }

        $result = PHP_OS_FAMILY === 'Darwin'
            ? Shell::exec(['sysctl', '-n', 'hw.ncpu'], 3)
            : Shell::exec(['nproc'], 3);

        return $cores = $result['code'] === 0 && trim($result['stdout']) !== ''
            ? max(1, (int) trim($result['stdout']))
            : 1;
    }

    /** Total physical RAM never changes at runtime, so this is cached for the process's lifetime. */
    private static function totalMemKb(): ?int
    {
        static $mem = false;
        if ($mem !== false) {
            return $mem;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $result = Shell::exec(['sysctl', '-n', 'hw.memsize'], 3);
            return $mem = $result['code'] === 0 && trim($result['stdout']) !== ''
                ? intdiv((int) trim($result['stdout']), 1024)
                : null;
        }

        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo !== false && preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $meminfo, $m)) {
            return $mem = (int) $m[1];
        }
        return $mem = null;
    }
}
