<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Authentication;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Security\Csrf;

final class CsrfTokenAction
{
    public static function handle(): never
    {
        JsonApi::respond(['ok' => true, 'token' => Csrf::token()]);
    }
}
