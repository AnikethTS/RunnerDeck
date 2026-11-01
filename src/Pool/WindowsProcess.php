<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use RunnerDeck\Core\Platform;
use RunnerDeck\Core\Shell;

final class WindowsProcess
{
    /** @return array<string, int> Platform::pathKey() of the runner dir => pid */
    public static function listenersByDirKey(): array
    {
        $script = 'Get-CimInstance Win32_Process -Filter "Name=\'Runner.Listener.exe\'"'
            . ' | Select-Object ProcessId,ExecutablePath | ConvertTo-Json -Compress';
        return self::parseListeners(Platform::powershell($script, 10));
    }

    /**
     * @param array{code: int, stdout: string, stderr: string} $result
     * @return array<string, int>
     */
    public static function parseListeners(array $result): array
    {
        $map = [];
        foreach (self::decodeRows($result) as $row) {
            $exe = $row['ExecutablePath'] ?? null;
            $pid = $row['ProcessId'] ?? null;
            if (!is_string($exe) || !is_int($pid)) {
                continue;
            }
            $key = Platform::pathKey(dirname(str_replace('\\', '/', $exe), 2));
            if ($key !== '') {
                $map[$key] = $pid;
            }
        }
        return $map;
    }

    /**
     * @return array<int, array{cpu_percent: float, rss_kb: int, uptime_seconds: int}>
     */
    public static function processStats(): array
    {
        $script = 'Get-CimInstance Win32_PerfFormattedData_PerfProc_Process'
            . ' | Select-Object IDProcess,PercentProcessorTime,WorkingSetPrivate,ElapsedTime'
            . ' | ConvertTo-Json -Compress';
        return self::parseStats(Platform::powershell($script, 10));
    }

    /**
     * @param array{code: int, stdout: string, stderr: string} $result
     * @return array<int, array{cpu_percent: float, rss_kb: int, uptime_seconds: int}>
     */
    public static function parseStats(array $result): array
    {
        $map = [];
        foreach (self::decodeRows($result) as $row) {
            $pid = $row['IDProcess'] ?? null;
            if (!is_int($pid) || $pid <= 0) {
                continue;
            }
            $map[$pid] = [
                'cpu_percent' => (float) ($row['PercentProcessorTime'] ?? 0),
                'rss_kb' => intdiv((int) ($row['WorkingSetPrivate'] ?? 0), 1024),
                'uptime_seconds' => (int) ($row['ElapsedTime'] ?? 0),
            ];
        }
        return $map;
    }

    public static function isListener(int $pid): bool
    {
        $script = 'Get-CimInstance Win32_Process -Filter "ProcessId=' . $pid . '"'
            . ' | Select-Object -ExpandProperty Name';
        $result = Platform::powershell($script, 8);
        return $result['code'] === 0 && stripos($result['stdout'], 'Runner.Listener') !== false;
    }

    public static function exists(int $pid): bool
    {
        $result = Shell::exec(['tasklist', '/FI', 'PID eq ' . $pid, '/NH', '/FO', 'CSV'], 8);
        return $result['code'] === 0 && str_contains($result['stdout'], '"' . $pid . '"');
    }

    // The runner has no window, so a non-forced taskkill cannot stop it.
    public static function kill(int $pid): bool
    {
        return Shell::exec(['taskkill', '/PID', (string) $pid, '/T', '/F'], 15)['code'] === 0;
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function spawnRunner(string $dir): array
    {
        $script = '$p = Start-Process -FilePath cmd.exe'
            . " -ArgumentList '/d','/c','run.cmd > runner.log 2>&1'"
            . ' -WorkingDirectory (Get-Location).Path -WindowStyle Hidden -PassThru;'
            . ' $p.Id';
        return Platform::powershell($script, 15, $dir);
    }

    /** @return array{0: int, 1: int}|null [total KB, available KB] */
    public static function memoryKb(): ?array
    {
        $script = '$o = Get-CimInstance Win32_OperatingSystem;'
            . ' "$($o.TotalVisibleMemorySize) $($o.FreePhysicalMemory)"';
        $result = Platform::powershell($script, 8);
        if ($result['code'] !== 0 || preg_match('/^(\d+)\s+(\d+)/', trim($result['stdout']), $m) !== 1) {
            return null;
        }
        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @param array{code: int, stdout: string, stderr: string} $result
     * @return list<array<string, mixed>>
     */
    private static function decodeRows(array $result): array
    {
        if ($result['code'] !== 0 || trim($result['stdout']) === '') {
            return [];
        }
        $json = json_decode(trim($result['stdout']), true);
        if (!is_array($json)) {
            return [];
        }
        // ConvertTo-Json emits a bare object for a single row.
        $rows = array_is_list($json) ? $json : [$json];
        return array_values(array_filter($rows, 'is_array'));
    }
}
