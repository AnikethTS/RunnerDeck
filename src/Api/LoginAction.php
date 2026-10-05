<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Auth;
use RunnerDeck\Csrf;

final class LoginAction
{
    public static function handle(): never
    {
        if (!Csrf::verifyRequest()) {
            JsonApi::respond(
                ['ok' => false, 'message' => 'missing or invalid CSRF token — reload the page and try again'],
                403
            );
        }
        if (!Auth::isEnabled()) {
            JsonApi::respond(['ok' => false, 'message' => 'login is not enabled'], 400);
        }
        $recovery = trim((string) ($_POST['recovery_code'] ?? ''));
        $ok = $recovery !== ''
            ? Auth::attemptRecovery($recovery)
            : Auth::attempt((string) ($_POST['code'] ?? ''));
        if (!$ok) {
            $lockout = Auth::lockoutStatus();
            if ($lockout['locked']) {
                JsonApi::respond(
                    ['ok' => false, 'message' => 'too many attempts', 'retryAfter' => $lockout['retryAfter'] ?? 0],
                    429
                );
            }
            JsonApi::respond(['ok' => false, 'message' => 'invalid code'], 401);
        }
        JsonApi::respond(['ok' => true]);
    }
}
