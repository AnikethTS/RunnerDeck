<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Authentication;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Security\Auth;

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
