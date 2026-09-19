<?php

declare(strict_types=1);

namespace RunnerDeck;

final class CrashWebhook
{
    private const TIMEOUT_SECONDS = 5;

    public static function notify(string $runnerId, string $agentName): void
    {
        $url = Config::crashWebhookUrl();
        if ($url === null) {
            return;
        }

        $body = json_encode(self::payloadFor($url, $runnerId, $agentName));
        if ($body === false) {
            return;
        }

        $result = Shell::exec(
            ['curl', '-fsS', '-m', (string) self::TIMEOUT_SECONDS,
                '-H', 'Content-Type: application/json', '-d', $body, $url],
            self::TIMEOUT_SECONDS + 2
        );

        if ($result['code'] !== 0) {
            AppLog::errorThrottled('crash_webhook', 'failed to deliver crash-loop notification', [
                'runner' => $runnerId,
                'stderr' => $result['stderr'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private static function payloadFor(string $url, string $runnerId, string $agentName): array
    {
        $message = "RunnerDeck: {$agentName} ({$runnerId}) crashed and needs attention.";

        if (str_contains($url, 'hooks.slack.com')) {
            return ['text' => $message];
        }
        if (str_contains($url, 'discord.com/api/webhooks') || str_contains($url, 'discordapp.com/api/webhooks')) {
            return ['content' => $message];
        }

        return [
            'event' => 'crash_loop',
            'runner' => $runnerId,
            'agent_name' => $agentName,
            'message' => $message,
            'time' => gmdate('c'),
        ];
    }
}
