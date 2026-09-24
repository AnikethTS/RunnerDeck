<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Drain;
use RunnerDeck\ProcessControl;
use RunnerDeck\RunnerPool;

final class DrainStopAllAction
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
        JsonApi::respond(ProcessControl::stopAll());
    }
}
