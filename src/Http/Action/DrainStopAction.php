<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\CrashState;
use RunnerDeck\Pool\Drain;
use RunnerDeck\Pool\ProcessControl;

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

        Drain::audit([$r->id], JsonApi::force());
        CrashState::markExplicitlyStopped($r->id);
        $stop = ProcessControl::stopIndividual($r);
        if (!empty($stop['ok'])) {
            $stop['message'] .= ProcessControl::maybeClearWork($r);
        }
        JsonApi::respond($stop);
    }
}
