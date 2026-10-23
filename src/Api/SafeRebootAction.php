<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\AppLog;
use RunnerDeck\Drain;
use RunnerDeck\HostMode;
use RunnerDeck\ProcessControl;
use RunnerDeck\RunnerPool;

final class SafeRebootAction
{
    public static function handle(): never
    {
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
        HostMode::setRebootReady(true);
        AppLog::info('host.reboot_ready', 'pool drain-stopped — safe to reboot this machine');
        JsonApi::respond([
            'ok' => true,
            'reboot_ready' => true,
            'message' => 'Pool is stopped. Reboot this machine when you want. '
                . (string) ($stop['message'] ?? ''),
        ]);
    }
}
