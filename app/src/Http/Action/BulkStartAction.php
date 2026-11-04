<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\ProcessControl;

final class BulkStartAction
{
    public static function handle(): never
    {
        JsonApi::respond(ProcessControl::bulkStart(JsonApi::requireRunners()));
    }
}
