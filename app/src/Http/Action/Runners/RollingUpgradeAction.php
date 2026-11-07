<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Runners;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Lifecycle\RollingRestart;
use RunnerDeck\Lifecycle\RollingUpgrade;
use RunnerDeck\Pool\RunnerPool;

final class RollingUpgradeAction
{
    public static function handle(): never
    {
        $timeout = JsonApi::drainTimeout();
        $runners = array_values(RunnerPool::discover(0));
        $configured = 0;
        foreach ($runners as $runner) {
            if ($runner->configured) {
                $configured++;
            }
        }
        set_time_limit((($timeout + 180) * max(1, $configured)) + 60);
        $result = RollingUpgrade::run($runners, JsonApi::force(), $timeout);
        JsonApi::respond($result, RollingRestart::status($result));
    }
}
