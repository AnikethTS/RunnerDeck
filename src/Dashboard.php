<?php

declare(strict_types=1);

final class Dashboard
{
    public static function snapshot(int $logLines = 15): array
    {
        $health = GithubClient::authStatus();

        $ghRunners = [];
        $ghError = null;
        if ($health->orgAccessOk) {
            try {
                $ghRunners = GithubClient::listRunners();
            } catch (RuntimeException $e) {
                $ghError = $e->getMessage();
            }
        }

        $runners = [];
        foreach (RunnerPool::discover($logLines) as $id => $info) {
            $row = $info->toArray();
            $gh = $ghRunners[$info->agentName] ?? null;
            $row['github'] = $gh === null ? null : [
                'status' => $gh['status'],
                'busy' => $gh['busy'],
                'labels' => $gh['labels'],
            ];
            $runners[] = $row;
        }

        return [
            'generated_at' => time(),
            'health' => array_merge($health->toArray(), ['gh_list_error' => $ghError]),
            'runners' => $runners,
            'stats' => self::computeStats($runners),
        ];
    }

    /** @param array<int, array<string, mixed>> $runners */
    private static function computeStats(array $runners): array
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

    /** @return array{0: bool, 1: bool, 2: ?string} [known, busy, error] */
    public static function isBusy(string $agentName): array
    {
        try {
            $runners = GithubClient::listRunners();
        } catch (RuntimeException $e) {
            return [false, false, $e->getMessage()];
        }
        $gh = $runners[$agentName] ?? null;
        if ($gh === null) {
            return [false, false, "no GitHub record for {$agentName}"];
        }
        return [true, $gh['busy'], null];
    }
}
