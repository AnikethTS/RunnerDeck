<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\RollingRestart;
use RunnerDeck\Pool\RollingUpgrade;
use RunnerDeck\Pool\RunnerPool;

final class RollingUpgradeAction
{
    public static function handle(): never
    {
        $timeout = JsonApi::drainTimeout();
        set_time_limit($timeout + 30);
        $runners = array_values(RunnerPool::discover(0));
        $result = RollingUpgrade::run($runners, JsonApi::force(), $timeout);
        JsonApi::respond($result, RollingRestart::status($result));
    }
}
