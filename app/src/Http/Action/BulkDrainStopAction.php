<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\Drain;
use RunnerDeck\Pool\ProcessControl;

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
        $ids = [];
        foreach ($runners as $r) {
            $ids[] = $r->id;
        }
        Drain::audit($ids, JsonApi::force());
        JsonApi::respond(ProcessControl::bulkStop($runners, true));
    }
}
