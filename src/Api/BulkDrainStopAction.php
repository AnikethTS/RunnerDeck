<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Drain;
use RunnerDeck\ProcessControl;

final class BulkDrainStopAction
{
    public static function handle(): never
    {
        $runners = JsonApi::requireRunners();
        $timeout = JsonApi::drainTimeout();
        set_time_limit($timeout + 30);

        if (!JsonApi::force()) {
            $wait = Drain::waitUntilNoneBusy($runners, $timeout);
            if (empty($wait['ok'])) {
                JsonApi::respond($wait, 409);
            }
        }

        JsonApi::markExplicitlyStopped($runners);
        JsonApi::respond(ProcessControl::bulkStop($runners));
    }
}
