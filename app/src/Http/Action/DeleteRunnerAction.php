<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\CrashState;
use RunnerDeck\Pool\ProcessControl;

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
