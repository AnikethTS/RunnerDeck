<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\History;

final class HistoryAction
{
    public static function handle(): never
    {
        JsonApi::respond(['ok' => true, 'available' => History::isAvailable(), 'samples' => History::recent()]);
    }
}
