<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Administration;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Logging\AppLog;

final class ClearApplogAction
{
    public static function handle(): never
    {
        AppLog::clear();
        JsonApi::respond(['ok' => true, 'message' => 'Log cleared']);
    }
}
