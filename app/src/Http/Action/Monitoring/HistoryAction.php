<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Monitoring;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Telemetry\History;

final class HistoryAction
{
    public static function handle(): never
    {
        JsonApi::respond(['ok' => true, 'available' => History::isAvailable(), 'samples' => History::recent()]);
    }
}
