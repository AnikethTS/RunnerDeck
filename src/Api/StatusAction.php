<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Config;
use RunnerDeck\Dashboard;

final class StatusAction
{
    public static function handle(): never
    {
        if (!Config::isConfigured()) {
            JsonApi::respond(['ok' => false, 'message' => 'RunnerDeck is not configured yet'], 409);
        }
        $lines = max(0, min(500, (int) ($_GET['lines'] ?? 15)));
        JsonApi::respond(Dashboard::snapshot($lines));
    }
}
