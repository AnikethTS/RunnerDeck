<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Pool\ProcessControl;

final class RenameAction
{
    public static function handle(): never
    {
        $r = JsonApi::requireRunner($_POST['runner'] ?? '');
        $newName = trim((string) ($_POST['name'] ?? ''));
        if ($newName === '') {
            JsonApi::respond(['ok' => false, 'message' => 'name is required'], 422);
        }
        if ($blocked = JsonApi::checkNotBusy($r->agentName, JsonApi::force())) {
            JsonApi::respond($blocked, 409);
        }
        JsonApi::respond(ProcessControl::renameRunner($r, $newName));
    }
}
