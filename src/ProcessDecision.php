<?php

declare(strict_types=1);

final class ProcessDecision
{
    /**
     * Whether start should spawn a new process, or treat one as already running.
     *
     * @param array{0: bool, 1: ?int} $fromPidfile
     * @param array<string, int> $liveByDir
     * @return array{running: bool, pid: ?int, rewrite_pidfile: bool}
     */
    public static function resolveStart(array $fromPidfile, array $liveByDir, string $dir): array
    {
        [$running, $pid] = $fromPidfile;
        if ($running) {
            return ['running' => true, 'pid' => $pid, 'rewrite_pidfile' => false];
        }

        $livePid = $liveByDir[$dir] ?? null;
        if ($livePid !== null) {
            return ['running' => true, 'pid' => $livePid, 'rewrite_pidfile' => true];
        }

        return ['running' => false, 'pid' => null, 'rewrite_pidfile' => false];
    }

    /**
     * Whether stop has a live process to signal.
     *
     * @param array{0: bool, 1: ?int} $fromPidfile
     * @param array<string, int> $liveByDir
     * @return array{running: bool, pid: ?int}
     */
    public static function resolveStop(array $fromPidfile, array $liveByDir, string $dir): array
    {
        [$running, $pid] = $fromPidfile;
        if ($running) {
            return ['running' => true, 'pid' => $pid];
        }

        $livePid = $liveByDir[$dir] ?? null;
        if ($livePid !== null) {
            return ['running' => true, 'pid' => $livePid];
        }

        return ['running' => false, 'pid' => $pid];
    }

    public static function parseSpawnedPid(string $stdout): int
    {
        return (int) trim($stdout);
    }
}
