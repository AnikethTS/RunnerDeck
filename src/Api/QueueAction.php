<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Config;
use RunnerDeck\QueuedJobs;

final class QueueAction
{
    public static function handle(): never
    {
        if (!Config::isConfigured()) {
            JsonApi::respond(['ok' => false, 'message' => 'RunnerDeck is not configured yet'], 409);
        }
        JsonApi::respond(QueuedJobs::snapshot());
    }
}
