<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\RollingRestart;

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
