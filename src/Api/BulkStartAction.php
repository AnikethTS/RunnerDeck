<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\ProcessControl;

final class BulkStartAction
{
    public static function handle(): never
    {
        JsonApi::respond(ProcessControl::bulkStart(JsonApi::requireRunners()));
    }
}
