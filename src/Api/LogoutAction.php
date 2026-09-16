<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Auth;

final class LogoutAction
{
    public static function handle(): never
    {
        if (Auth::isEnabled()) {
            Auth::logout();
        }
        JsonApi::respond(['ok' => true]);
    }
}
