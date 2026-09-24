<?php

declare(strict_types=1);

namespace RunnerDeck;

final class CrashWebhook
{
    private const TIMEOUT_SECONDS = 5;

    public static function isValidUrl(string $url): bool
    {
        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
    }

    public static function notify(
        string $runnerId,
        string $agentName,
        string $event = 'crash_loop',
        ?int $count = null,
        ?int $threshold = null
    ): void {
        $url = Config::crashWebhookUrl();
        if ($url === null) {
            return;
        }

        if ($event === 'crash_threshold' && $count !== null && $threshold !== null) {
            $message = "RunnerDeck: {$agentName} ({$runnerId}) reached {$count} crashes in 7 days "
                . "(threshold {$threshold}).";
        } else {
            $message = "RunnerDeck: {$agentName} ({$runnerId}) crashed and needs attention.";
            $event = 'crash_loop';
        }
        $payload = self::payloadFor($url, $event, $message, $runnerId, $agentName, $count, $threshold);
        [$ok, $stderr] = self::post($url, $payload);

        if (!$ok) {
            AppLog::errorThrottled('crash_webhook', 'failed to deliver crash notification', [
                'runner' => $runnerId,
                'stderr' => $stderr,
            ]);
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function sendTest(string $url): array
    {
        $message = 'RunnerDeck: this is a test notification from Settings.';
        $payload = self::payloadFor($url, 'test', $message, null, null);
        [$ok, $stderr] = self::post($url, $payload);

        if ($ok) {
            return ['ok' => true, 'message' => 'Test notification sent.'];
        }

        AppLog::errorThrottled('crash_webhook', 'failed to deliver test notification', ['stderr' => $stderr]);
        return [
            'ok' => false,
            'message' => trim($stderr) !== '' ? "Delivery failed: {$stderr}" : 'Delivery failed.',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string}
     */
    private static function post(string $url, array $payload): array
    {
        $body = json_encode($payload);
        if ($body === false) {
            return [false, 'failed to encode payload'];
        }

        $result = Shell::exec(
            ['curl', '-fsS', '-m', (string) self::TIMEOUT_SECONDS,
                '-H', 'Content-Type: application/json', '-d', $body, $url],
            self::TIMEOUT_SECONDS + 2
        );

        return [$result['code'] === 0, $result['stderr']];
    }

    /** @return array<string, mixed> */
    private static function payloadFor(
        string $url,
        string $event,
        string $message,
        ?string $runnerId,
        ?string $agentName,
        ?int $count = null,
        ?int $threshold = null
    ): array {
        if (str_contains($url, 'hooks.slack.com')) {
            return ['text' => $message];
        }
        if (str_contains($url, 'discord.com/api/webhooks') || str_contains($url, 'discordapp.com/api/webhooks')) {
            return ['content' => $message];
        }

        $payload = ['event' => $event, 'message' => $message, 'time' => gmdate('c')];
        if ($runnerId !== null) {
            $payload['runner'] = $runnerId;
            $payload['agent_name'] = $agentName;
        }
        if ($count !== null) {
            $payload['crash_count_7d'] = $count;
        }
        if ($threshold !== null) {
            $payload['threshold'] = $threshold;
        }
        return $payload;
    }
}
