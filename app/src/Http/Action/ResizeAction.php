<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\ProcessControl;

final class ResizeAction
{
    public static function handle(): never
    {
        $count = max(1, min(30, (int) ($_POST['count'] ?? 10)));
        JsonApi::respond(ProcessControl::resizePool($count));
    }
}
