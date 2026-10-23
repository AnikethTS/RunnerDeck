<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\AppLog;
use RunnerDeck\Drain;
use RunnerDeck\HostMode;
use RunnerDeck\ProcessControl;
use RunnerDeck\RunnerPool;

final class MaintenanceAction
{
    public static function handle(): never
    {
        $on = ($_POST['enabled'] ?? '') === '1';
        if ($on) {
            HostMode::setMaintenance(true);
            $allRunners = RunnerPool::discover(0);
            $timeout = JsonApi::drainTimeout();
            set_time_limit($timeout + 30);
            if (!JsonApi::force()) {
                $wait = Drain::waitUntilNoneBusy($allRunners, $timeout);
                if (empty($wait['ok'])) {
                    JsonApi::respond($wait, 409);
                }
            }
            JsonApi::markExplicitlyStopped($allRunners);
            $ids = [];
            foreach ($allRunners as $r) {
                $ids[] = $r->id;
            }
            Drain::audit($ids, JsonApi::force());
            $stop = ProcessControl::stopAll(true);
            AppLog::info('host.maintenance', 'maintenance on — runners drain-stopped');
            JsonApi::respond([
                'ok' => true,
                'maintenance' => true,
                'message' => 'Maintenance on. ' . (string) ($stop['message'] ?? ''),
            ]);
        }

        HostMode::setMaintenance(false);
        AppLog::info('host.maintenance', 'maintenance off');
        JsonApi::respond([
            'ok' => true,
            'maintenance' => false,
            'message' => 'Maintenance off. Start runners when you want them back.',
        ]);
    }
}
