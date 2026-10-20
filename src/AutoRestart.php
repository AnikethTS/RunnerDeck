<?php

declare(strict_types=1);

namespace RunnerDeck;

final class AutoRestart
{
    /**
     * Local process check + start for flagged crashes, ephemeral
     * reprovision, and optional scale-to-queue. Does not write CPU/RAM
     * history — dashboard polls still own those.
     *
     * @return list<array{ok: bool, message: string}>
     */
    public static function tick(): array
    {
        if (!Config::isConfigured() || HostMode::isMaintenance()) {
            return [];
        }
        $wantCrash = Config::autoRestartEnabled();
        $wantScale = Config::scaleToQueueEnabled();
        if (!$wantCrash && !$wantScale && !self::poolHasEphemeral()) {
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
        $out = self::startFlagged($rows);
        if ($wantScale) {
            $out = array_merge($out, self::scaleToQueue($rows));
        }
        return $out;
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
            if (empty($row['should_auto_restart']) || !empty($row['auto_restart_paused'])) {
                continue;
            }
            $started = self::startRow($row);
            if ($started !== null) {
                $out[] = $started;
            }
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $runners
     * @return list<array{ok: bool, message: string}>
     */
    public static function scaleToQueue(array $runners): array
    {
        $queued = QueuedJobs::snapshot()['count'];
        if ($queued < 1) {
            return [];
        }
        $out = [];
        $started = 0;
        foreach ($runners as $row) {
            if ($started >= $queued) {
                break;
            }
            if (empty($row['configured']) || !empty($row['local_running'])) {
                continue;
            }
            if (!empty($row['auto_restart_paused']) || !empty($row['should_auto_restart'])) {
                continue;
            }
            $one = self::startRow($row);
            if ($one === null) {
                continue;
            }
            $out[] = $one;
            if (!empty($one['ok'])) {
                $started++;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok: bool, message: string}|null
     */
    private static function startRow(array $row): ?array
    {
        $id = (string) ($row['id'] ?? '');
        if ($id === '' || !RunnerPool::isKnownId($id)) {
            return null;
        }
        $info = new RunnerInfo(
            id: $id,
            dir: RunnerPool::dirFor($id),
            configured: (bool) ($row['configured'] ?? false),
            agentName: (string) ($row['agent_name'] ?? $id),
            localRunning: false,
            pid: null,
            logTail: [],
            ephemeral: !empty($row['ephemeral']),
        );
        $started = ProcessControl::startIndividual($info);
        return [
            'ok' => (bool) ($started['ok'] ?? false),
            'message' => (string) ($started['message'] ?? ''),
        ];
    }

    private static function poolHasEphemeral(): bool
    {
        foreach (RunnerPool::discover(0) as $info) {
            if ($info->ephemeral) {
                return true;
            }
        }
        return false;
    }
}
