<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\ProcessControl;

final class StartAllAction
{
    public static function handle(): never
    {
        $count = max(1, min(30, (int) ($_POST['count'] ?? 10)));
        JsonApi::respond(ProcessControl::startAll($count));
    }
}
