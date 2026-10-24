<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Host\History;

final class HistoryAction
{
    public static function handle(): never
    {
        JsonApi::respond(['ok' => true, 'available' => History::isAvailable(), 'samples' => History::recent()]);
    }
}
