<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\SystemStats;

final class SystemAction
{
    public static function handle(): never
    {
        JsonApi::respond(['ok' => true, 'system' => SystemStats::snapshot()]);
    }
}
