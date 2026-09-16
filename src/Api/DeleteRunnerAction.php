<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\CrashState;
use RunnerDeck\ProcessControl;

final class DeleteRunnerAction
{
    public static function handle(): never
    {
        $r = JsonApi::requireRunner($_POST['runner'] ?? '');
        if ($blocked = JsonApi::checkNotBusy($r->agentName, JsonApi::force())) {
            JsonApi::respond($blocked, 409);
        }
        CrashState::markExplicitlyStopped($r->id);
        JsonApi::respond(ProcessControl::deleteRunner($r));
    }
}
