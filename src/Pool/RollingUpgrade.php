<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use RunnerDeck\Core\AppLog;

final class RollingUpgrade
{
    /**
     * Drain-stop, replace the local agent package, start — one slot at a time.
     *
     * @param RunnerInfo[] $runners
     * @return array{ok: bool, message?: string, busy?: bool, busy_unknown?: bool, timed_out?: bool}
     */
    public static function run(array $runners, bool $force, int $timeout): array
    {
        $configured = [];
        foreach ($runners as $r) {
            if ($r->configured) {
                $configured[] = $r;
            }
        }
        if ($configured === []) {
            return ['ok' => true, 'message' => 'No configured runners to upgrade'];
        }
        try {
            $download = Provisioner::pickDownloadForHost();
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $target = Provisioner::versionFromDownload($download) ?? 'latest';
        $done = [];
        $skipped = [];
        foreach ($configured as $r) {
            if ($r->agentVersion !== null && $r->agentVersion === $target) {
                $skipped[] = $r->id;
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
            $stop = ProcessControl::stopIndividual($r);
            if (empty($stop['ok'])) {
                return ['ok' => false, 'message' => (string) ($stop['message'] ?? 'stop failed')];
            }
            $install = Provisioner::ensureInstalled($r->dir, true);
            if (empty($install['ok'])) {
                return [
                    'ok' => false,
                    'message' => (string) ($install['message'] ?? 'install failed'),
                ];
            }
            $start = ProcessControl::startIndividual($r);
            if (empty($start['ok'])) {
                return [
                    'ok' => false,
                    'message' => (string) ($start['message'] ?? 'start failed'),
                ];
            }
            AppLog::info(
                'rolling_upgrade',
                "{$r->id} agent upgraded toward {$target}",
                ['runner' => $r->id]
            );
            $done[] = $r->id;
        }
        if ($done === [] && $skipped === []) {
            return ['ok' => true, 'message' => 'No configured runners to upgrade'];
        }
        $parts = [];
        if ($done !== []) {
            $parts[] = count($done) . ' upgraded to ' . $target . ': ' . implode(', ', $done);
        }
        if ($skipped !== []) {
            $parts[] = count($skipped) . ' already on ' . $target;
        }
        return ['ok' => true, 'message' => implode('. ', $parts)];
    }
}
