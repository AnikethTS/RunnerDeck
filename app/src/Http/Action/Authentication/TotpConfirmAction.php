<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Authentication;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Security\Auth;

final class TotpConfirmAction
{
    public static function handle(): never
    {
        if (!Auth::confirmTotpSetup((string) ($_POST['code'] ?? ''))) {
            JsonApi::respond(['ok' => false, 'message' => 'Invalid code'], 422);
        }
        JsonApi::respond(['ok' => true, 'recovery_codes' => Auth::takeIssuedRecoveryCodes()]);
    }
}
