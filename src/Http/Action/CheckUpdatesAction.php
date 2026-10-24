<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Core\Config;
use RunnerDeck\GitHub\UpdateCheck;

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
