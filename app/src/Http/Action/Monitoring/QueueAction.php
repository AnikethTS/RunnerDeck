<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Monitoring;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Configuration\Config;
use RunnerDeck\GitHub\QueuedJobs;

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
