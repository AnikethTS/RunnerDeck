<?php

declare(strict_types=1);

namespace RunnerDeck;

final class RollingRestart
{
    /**
     * Drain-stop then start, one configured slot at a time.
     *
     * @param RunnerInfo[] $runners
     * @return array{ok: bool, message?: string, busy?: bool, busy_unknown?: bool, timed_out?: bool}
     */
    public static function run(array $runners, bool $force, int $timeout): array
    {
        $done = [];
        foreach ($runners as $r) {
            if (!$r->configured) {
                continue;
            }
            if (!$force) {
                $wait = Drain::waitUntilIdle($r->agentName, $timeout);
                if (empty($wait['ok'])) {
                    return $wait;
                }
            }
            CrashState::markExplicitlyStopped($r->id);
            Drain::audit([$r->id], $force);
            $result = ProcessControl::restartIndividual($r);
            if (empty($result['ok'])) {
                return [
                    'ok' => false,
                    'message' => (string) ($result['message'] ?? 'restart failed'),
                ];
            }
            AppLog::info('rolling_restart', "{$r->id} rolling restart", ['runner' => $r->id]);
            $done[] = $r->id;
        }
        if ($done === []) {
            return ['ok' => true, 'message' => 'No configured runners to restart'];
        }
        return [
            'ok' => true,
            'message' => count($done) . ' runner(s) restarted: ' . implode(', ', $done),
        ];
    }

    /** @param array{ok: bool, busy?: bool, busy_unknown?: bool, timed_out?: bool} $result */
    public static function status(array $result): int
    {
        if (
            empty($result['ok'])
            && (!empty($result['busy']) || !empty($result['busy_unknown']) || !empty($result['timed_out']))
        ) {
            return 409;
        }
        return 200;
    }
}
