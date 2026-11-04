<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Authentication;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Security\Auth;

final class TotpBeginAction
{
    public static function handle(): never
    {
        $secret = Auth::beginTotpSetup();
        $uri = 'otpauth://totp/RunnerDeck?secret=' . $secret . '&issuer=RunnerDeck';
        JsonApi::respond(['ok' => true, 'secret' => $secret, 'uri' => $uri]);
    }
}
