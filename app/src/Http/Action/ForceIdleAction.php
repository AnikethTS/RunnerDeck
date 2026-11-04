<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\ProcessControl;

final class ForceIdleAction
{
    public static function handle(): never
    {
        $runner = JsonApi::requireRunner((string) ($_POST['runner'] ?? ''));
        JsonApi::respond(ProcessControl::forceIdle($runner));
    }
}
