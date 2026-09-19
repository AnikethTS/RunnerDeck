<?php

declare(strict_types=1);

namespace RunnerDeck;

final class Dashboard
{
    public static function snapshot(int $logLines = 15): array
    {
        $login = GithubClient::checkLogin();

        $ghRunners = [];
        $orgAccessOk = false;
        $message = $login['message'];

        if ($login['logged_in']) {
            try {
                $ghRunners = GithubClient::listRunners();
                $orgAccessOk = true;
                $message = 'OK';
            } catch (\RuntimeException $e) {
                $scopeLabel = Config::scope() === 'repo' ? 'repo' : 'org';
                $message = "Logged in, but cannot read {$scopeLabel} runners: " . $e->getMessage();
            }
        }

        $discovered = RunnerPool::discover($logLines);
        $runners = [];
        foreach ($discovered as $info) {
            $row = $info->toArray();
            $gh = $ghRunners[$info->agentName] ?? null;
            $row['github'] = $gh === null ? null : [
                'status' => $gh['status'],
                'busy' => $gh['busy'],
                'labels' => $gh['labels'],
            ];
            $runners[] = $row;
        }

        $runners = CrashState::track($runners);

        $stats = self::computeStats($runners);
        History::record($stats['avg_cpu_percent'] ?? 0.0, $stats['total_rss_kb'] ?? 0);

        return [
            'generated_at' => time(),
            'health' => [
                'logged_in' => $login['logged_in'],
                'org_access_ok' => $orgAccessOk,
                'message' => $message,
            ],
            'runners' => $runners,
            'stats' => $stats,
            'system' => SystemStats::snapshot(),
            'history' => self::historyView(),
        ];
    }

    /** @param array<int, array<string, mixed>> $runners */
    public static function computeStats(array $runners): array
    {
        $running = array_values(array_filter($runners, static fn($r) => $r['local_running']));
        $notNull = static fn($v) => $v !== null;
        $cpuValues = array_values(array_filter(array_column($running, 'cpu_percent'), $notNull));
        $rssValues = array_values(array_filter(array_column($running, 'rss_kb'), $notNull));

        return [
            'total' => count($runners),
            'running' => count($running),
            'avg_cpu_percent' => $cpuValues ? array_sum($cpuValues) / count($cpuValues) : null,
            'total_rss_kb' => $rssValues ? array_sum($rssValues) : null,
        ];
    }

    /** @return array{available: bool, cpu_svg: string, mem_svg: string} */
    public static function historyView(): array
    {
        $unavailable = !History::isAvailable();
        $samples = History::recent();
        $cpu = array_map(static fn (array $s): float => (float) $s['avg_cpu'], $samples);
        $mem = array_map(static fn (array $s): float => ((int) $s['total_rss_kb']) / 1024.0, $samples);
        $memMax = $mem === [] ? 10.0 : (float) max(10.0, ...$mem);

        return [
            'available' => !$unavailable,
            'cpu_svg' => History::polylineSvg($cpu, 0.0, 100.0, $unavailable),
            'mem_svg' => History::polylineSvg($mem, 0.0, $memMax, $unavailable),
        ];
    }

    /** @return array{0: bool, 1: bool, 2: ?string} [known, busy, error] */
    public static function isBusy(string $agentName): array
    {
        try {
            $runners = GithubClient::listRunners();
        } catch (\RuntimeException $e) {
            return [false, false, $e->getMessage()];
        }
        $gh = $runners[$agentName] ?? null;
        if ($gh === null) {
            return [false, false, "no GitHub record for {$agentName}"];
        }
        return [true, $gh['busy'], null];
    }
}
