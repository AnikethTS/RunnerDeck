<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

use RunnerDeck\Http\Api;
use RunnerDeck\Lifecycle\Drain;
use RunnerDeck\Lifecycle\RollingRestart;
use RunnerDeck\Host\HostMode;
use RunnerDeck\Logging\AppLog;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\RunnerInfo;
use RunnerDeck\Pool\RunnerPool;

final class Ops
{
    /**
     * @param list<RunnerInfo> $runners
     * @return array<string, mixed>
     */
    public static function rollingRestart(array $runners, bool $force, int $timeout): array
    {
        return RollingRestart::run($runners, $force, $timeout);
    }

    /**
     * @param list<RunnerInfo> $runners
     * @return array<string, mixed>
     */
    public static function drain(array $runners, bool $force, int $timeout): array
    {
        if (!$force) {
            $wait = Drain::waitUntilNoneBusy($runners, $timeout);
            if (empty($wait['ok'])) {
                return $wait;
            }
        }
        Api::markExplicitlyStopped($runners);
        Drain::audit(array_map(static fn (RunnerInfo $r): string => $r->id, $runners), $force);
        return ProcessControl::bulkStop($runners, true);
    }

    /** @return array<string, mixed> */
    public static function maintenance(bool $on, bool $force, int $timeout): array
    {
        if (!$on) {
            HostMode::setMaintenance(false);
            AppLog::info('host.maintenance', 'maintenance off');
            return [
                'ok' => true,
                'maintenance' => false,
                'message' => 'Maintenance off. Start runners when you want them back.',
            ];
        }
        HostMode::setMaintenance(true);
        $all = array_values(RunnerPool::discover(0));
        $drained = self::drain($all, $force, $timeout);
        if (empty($drained['ok'])) {
            return $drained;
        }
        AppLog::info('host.maintenance', 'maintenance on - runners drain-stopped');
        return [
            'ok' => true,
            'maintenance' => true,
            'message' => trim('Maintenance on. ' . (string) ($drained['message'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    public static function rename(RunnerInfo $runner, string $name, bool $force): array
    {
        $blocked = Api::checkNotBusy($runner->agentName, $force);
        return $blocked ?? ProcessControl::renameRunner($runner, $name);
    }

    /** @return array<string, mixed> */
    public static function resize(int $count): array
    {
        return ProcessControl::resizePool(max(1, min(30, $count)));
    }
}
