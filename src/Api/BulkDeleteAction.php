<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\ProcessControl;

final class BulkDeleteAction
{
    public static function handle(): never
    {
        $runners = JsonApi::requireRunners();
        if ($blocked = JsonApi::checkNoneBusy($runners, JsonApi::force())) {
            JsonApi::respond($blocked, 409);
        }
        JsonApi::markExplicitlyStopped($runners);
        JsonApi::respond(ProcessControl::bulkDelete($runners));
    }
}
