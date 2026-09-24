<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\CrashState;
use RunnerDeck\Drain;
use RunnerDeck\ProcessControl;

final class DrainStopAction
{
    public static function handle(): never
    {
        $r = JsonApi::requireRunner($_POST['runner'] ?? '');
        $timeout = JsonApi::drainTimeout();
        set_time_limit($timeout + 30);

        if (!JsonApi::force()) {
            $wait = Drain::waitUntilIdle($r->agentName, $timeout);
            if (empty($wait['ok'])) {
                JsonApi::respond($wait, 409);
            }
        }

        CrashState::markExplicitlyStopped($r->id);
        JsonApi::respond(ProcessControl::stopIndividual($r));
    }
}
