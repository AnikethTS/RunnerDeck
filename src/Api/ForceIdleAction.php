<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\ProcessControl;

final class ForceIdleAction
{
    public static function handle(): never
    {
        $runner = JsonApi::requireRunner((string) ($_POST['runner'] ?? ''));
        JsonApi::respond(ProcessControl::forceIdle($runner));
    }
}
