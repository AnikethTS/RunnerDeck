<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\AppLog;

final class ClearApplogAction
{
    public static function handle(): never
    {
        AppLog::clear();
        JsonApi::respond(['ok' => true, 'message' => 'Log cleared']);
    }
}
