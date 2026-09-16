<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Csrf;

final class CsrfTokenAction
{
    public static function handle(): never
    {
        JsonApi::respond(['ok' => true, 'token' => Csrf::token()]);
    }
}
