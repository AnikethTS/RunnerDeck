<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\RollingRestart;

final class RollingRestartAction
{
    public static function handle(): never
    {
        $r = JsonApi::requireRunner($_POST['runner'] ?? '');
        $timeout = JsonApi::drainTimeout();
        set_time_limit($timeout + 30);
        $result = RollingRestart::run([$r], JsonApi::force(), $timeout);
        JsonApi::respond($result, RollingRestart::status($result));
    }
}
