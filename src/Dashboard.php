<?php

final class Dashboard
{
    public static function snapshot(int $logLines = 15): array
    {
        $health = GithubClient::authStatus();

        $ghRunners = [];
        $ghError = null;
        if ($health->orgAccessOk) {
            try {
                $ghRunners = GithubClient::listOrgRunners();
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
        ];
    }

    /** @return array{0: bool, 1: bool, 2: ?string} [known, busy, error] */
    public static function isBusy(string $agentName): array
    {
        try {
            $runners = GithubClient::listOrgRunners();
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
