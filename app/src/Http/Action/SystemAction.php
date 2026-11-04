<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Host\SystemStats;

final class SystemAction
{
    public static function handle(): never
    {
        JsonApi::respond(['ok' => true, 'system' => SystemStats::snapshot()]);
    }
}
