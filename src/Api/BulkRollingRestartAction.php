<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\RollingRestart;

final class BulkRollingRestartAction
{
    public static function handle(): never
    {
        $runners = JsonApi::requireRunners();
        $timeout = JsonApi::drainTimeout();
        set_time_limit(($timeout * max(1, count($runners))) + 60);
        $result = RollingRestart::run($runners, JsonApi::force(), $timeout);
        JsonApi::respond($result, RollingRestart::status($result));
    }
}
