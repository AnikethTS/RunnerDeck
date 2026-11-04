<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Core\AppLog;
use RunnerDeck\Pool\CrashState;

final class PauseAutoRestartAction
{
    public static function handle(): never
    {
        $r = JsonApi::requireRunner($_POST['runner'] ?? '');
        $paused = ($_POST['paused'] ?? '1') === '1';
        CrashState::setAutoRestartPaused($r->id, $paused);
        $event = $paused ? 'auto_restart.paused' : 'auto_restart.resumed';
        $verb = $paused ? 'paused' : 'resumed';
        AppLog::info($event, "{$r->id} auto-restart {$verb}", ['runner' => $r->id]);
        JsonApi::respond([
            'ok' => true,
            'paused' => $paused,
            'message' => "{$r->id} auto-restart {$verb}",
        ]);
    }
}
