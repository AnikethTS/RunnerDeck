<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Runners;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\ProcessControl;

final class ClearWorkAction
{
    public static function handle(): never
    {
        $r = JsonApi::requireRunner($_POST['runner'] ?? '');
        $result = ProcessControl::clearWorkDir($r);
        JsonApi::respond($result, $result['ok'] ? 200 : 409);
    }
}
