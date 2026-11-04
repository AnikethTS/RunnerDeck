<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Alert\CrashWebhook;

final class TestWebhookAction
{
    public static function handle(): never
    {
        $url = trim((string) ($_POST['crash_webhook_url'] ?? ''));
        if ($url === '' || !CrashWebhook::isValidUrl($url)) {
            JsonApi::respond(
                ['ok' => false, 'message' => 'Enter a webhook URL starting with http:// or https:// first.'],
                422
            );
        }

        $result = CrashWebhook::sendTest($url);
        JsonApi::respond($result, $result['ok'] ? 200 : 502);
    }
}
