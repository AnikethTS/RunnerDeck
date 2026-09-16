<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\ProcessControl;
use RunnerDeck\RunnerPool;

final class StopAllAction
{
    public static function handle(): never
    {
        $allRunners = RunnerPool::discover(0);
        if ($blocked = JsonApi::checkNoneBusy($allRunners, JsonApi::force())) {
            JsonApi::respond($blocked, 409);
        }
        JsonApi::markExplicitlyStopped($allRunners);
        JsonApi::respond(ProcessControl::stopAll());
    }
}
