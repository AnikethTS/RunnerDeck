<?php

declare(strict_types=1);

final class Shell
{
    public static function exec(array $cmd, int $timeoutSec = 20, ?string $cwd = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'failed to start process: ' . implode(' ', $cmd)];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedOut = false;
        $exitCode = null;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($proc);
            if (!$status['running']) {
                // proc_get_status() only reports the real exit code the
                // first time it's called after the process ends; capture
                // it here rather than relying on proc_close()'s return
                // value below, which can come back -1 once that status has
                // already been collected once.
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) - $start > $timeoutSec) {
                $timedOut = true;
                proc_terminate($proc, 15);
                usleep(200000);
                proc_terminate($proc, 9);
                break;
            }
            usleep(50000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($proc);
        $code = $exitCode ?? $closeCode;

        if ($timedOut) {
            $stderr .= "\n[timed out after {$timeoutSec}s, process killed]";
            $code = $code === 0 ? -1 : $code;
        }

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
