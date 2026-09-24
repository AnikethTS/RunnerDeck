<?php

declare(strict_types=1);

namespace RunnerDeck;

final class AutoRestart
{
    /**
     * Local process check + start for flagged crashes. Does not call GitHub
     * and does not write CPU/RAM history — dashboard polls still own those.
     *
     * @return list<array{ok: bool, message: string}>
     */
    public static function tick(): array
    {
        if (!Config::isConfigured() || !Config::autoRestartEnabled()) {
            return [];
        }

        $rows = [];
        foreach (RunnerPool::discover(0) as $info) {
            $row = $info->toArray();
            $row['github'] = null;
            $rows[] = $row;
        }
        $rows = CrashState::track($rows, false);
        Dashboard::notifyCrashes($rows);
        return self::startFlagged($rows);
    }

    /**
     * CSRF start path used by the dashboard, invoked here without HTTP.
     *
     * @param array<int, array<string, mixed>> $runners
     * @return list<array{ok: bool, message: string}>
     */
    public static function startFlagged(array $runners): array
    {
        $out = [];
        foreach ($runners as $row) {
            if (empty($row['should_auto_restart'])) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || !RunnerPool::isKnownId($id)) {
                continue;
            }
            $info = new RunnerInfo(
                id: $id,
                dir: RunnerPool::dirFor($id),
                configured: (bool) ($row['configured'] ?? false),
                agentName: (string) ($row['agent_name'] ?? $id),
                localRunning: false,
                pid: null,
                logTail: [],
            );
            $started = ProcessControl::startIndividual($info);
            $out[] = [
                'ok' => (bool) ($started['ok'] ?? false),
                'message' => (string) ($started['message'] ?? ''),
            ];
        }
        return $out;
    }
}
