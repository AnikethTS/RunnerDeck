<?php

declare(strict_types=1);

namespace RunnerDeck;

final class AutoRestart
{
    /**
     * Local process check + start for flagged crashes, ephemeral
     * reprovision, optional scale-to-queue, and optional idle-stop.
     * Does not write CPU/RAM history — dashboard polls still own those.
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
        $wantIdle = Config::idleStopMinutes() !== null;
        if (!$wantCrash && !$wantScale && !$wantIdle && !self::poolHasEphemeral()) {
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
        $queued = ($wantScale || $wantIdle) ? QueuedJobs::snapshot()['count'] : 0;
        if ($wantScale) {
            $out = array_merge($out, self::scaleToQueue($rows, $queued));
        }
        if ($wantIdle && $queued < 1) {
            $out = array_merge($out, self::idleStop($rows));
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
    public static function scaleToQueue(array $runners, ?int $queued = null): array
    {
        $queued = $queued ?? QueuedJobs::snapshot()['count'];
        if ($queued < 1) {
            return [];
        }
        $room = self::startRoom($queued);
        if ($room < 1) {
            return [];
        }
        $out = [];
        $started = 0;
        foreach ($runners as $row) {
            if ($started >= $room) {
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
     * @param array<int, array<string, mixed>> $runners
     * @return list<array{ok: bool, message: string}>
     */
    public static function idleStop(array $runners): array
    {
        $minutes = Config::idleStopMinutes();
        if ($minutes === null) {
            return [];
        }
        $rows = self::withGithub($runners);
        IdleState::sync($rows);
        $out = [];
        foreach (IdleState::dueIds($minutes) as $id) {
            if (!RunnerPool::isKnownId($id)) {
                continue;
            }
            $info = RunnerPool::discover(0)[$id] ?? null;
            if ($info === null || !$info->localRunning) {
                continue;
            }
            $stopped = ProcessControl::stopIndividual($info);
            CrashState::markExplicitlyStopped($id);
            $out[] = [
                'ok' => (bool) ($stopped['ok'] ?? false),
                'message' => (string) ($stopped['message'] ?? ''),
            ];
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

    /**
     * @param array<int, array<string, mixed>> $runners
     * @return array<int, array<string, mixed>>
     */
    private static function withGithub(array $runners): array
    {
        try {
            $gh = GithubClient::listRunners();
        } catch (\RuntimeException) {
            return $runners;
        }
        foreach ($runners as $i => $row) {
            $name = (string) ($row['agent_name'] ?? '');
            $runners[$i]['github'] = $gh[$name] ?? null;
        }
        return $runners;
    }

    private static function startRoom(int $queued): int
    {
        $room = max(0, $queued);
        $cap = Config::poolCap();
        if ($cap !== null) {
            $room = min($room, max(0, $cap - ProcessControl::runningCount()));
        }
        return $room;
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
