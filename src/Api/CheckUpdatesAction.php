<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Config;
use RunnerDeck\UpdateCheck;

final class CheckUpdatesAction
{
    public static function handle(): never
    {
        if (!Config::checkUpdatesEnabled()) {
            JsonApi::respond(['ok' => false, 'message' => 'update checks are disabled'], 403);
        }
        JsonApi::respond(['ok' => true] + UpdateCheck::check());
    }
}
