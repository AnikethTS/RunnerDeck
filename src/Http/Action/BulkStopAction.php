<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\ProcessControl;

final class BulkStopAction
{
    public static function handle(): never
    {
        $runners = JsonApi::requireRunners();
        if ($blocked = JsonApi::checkNoneBusy($runners, JsonApi::force())) {
            JsonApi::respond($blocked, 409);
        }
        JsonApi::markExplicitlyStopped($runners);
        JsonApi::respond(ProcessControl::bulkStop($runners));
    }
}
