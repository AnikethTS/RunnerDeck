<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\ProcessControl;

final class ClearWorkAction
{
    public static function handle(): never
    {
        $r = JsonApi::requireRunner($_POST['runner'] ?? '');
        $result = ProcessControl::clearWorkDir($r);
        JsonApi::respond($result, $result['ok'] ? 200 : 409);
    }
}
