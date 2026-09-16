<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\ProcessControl;

final class AddRunnerAction
{
    public static function handle(): never
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        JsonApi::respond(ProcessControl::addRunner($name !== '' ? $name : null));
    }
}
