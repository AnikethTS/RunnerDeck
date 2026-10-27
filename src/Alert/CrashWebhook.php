<?php

declare(strict_types=1);

namespace RunnerDeck\Alert;

use RunnerDeck\Core\AppLog;
use RunnerDeck\Core\Config;
use RunnerDeck\Core\Shell;

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
        if ($event === 'crash_threshold' && $count !== null && $threshold !== null) {
            $message = "RunnerDeck: {$agentName} ({$runnerId}) reached {$count} crashes in 7 days "
                . "(threshold {$threshold}).";
        } elseif ($event === 'mismatch') {
            $message = "RunnerDeck: {$agentName} ({$runnerId}) local process and GitHub status disagree.";
        } else {
            $message = "RunnerDeck: {$agentName} ({$runnerId}) crashed and needs attention.";
            $event = 'crash_loop';
        }
        SyslogSink::emit($event, $message);
        $url = Config::crashWebhookUrl();
        if ($url === null) {
            return;
        }
        $payload = self::payloadFor($url, $event, $message, $runnerId, $agentName, $count, $threshold);
        self::deliver($url, $payload, $event, $message, $runnerId);
    }

    public static function wantsNotify(): bool
    {
        return Config::crashWebhookUrl() !== null || SyslogSink::enabled();
    }

    public static function notifyEvent(string $event, string $message): void
    {
        SyslogSink::emit($event, $message);
        $url = Config::crashWebhookUrl();
        if ($url === null) {
            return;
        }
        $payload = self::payloadFor($url, $event, $message, null, null);
        self::deliver($url, $payload, $event, $message, null);
    }

    public static function notifyDisk(
        string $event,
        string $message,
        ?string $runnerId,
        ?string $agentName,
        int $diskKb,
        int $thresholdGb
    ): void {
        SyslogSink::emit($event, $message);
        $url = Config::crashWebhookUrl();
        if ($url === null) {
            return;
        }

        $payload = self::payloadFor($url, $event, $message, $runnerId, $agentName, null, $thresholdGb, $diskKb);
        self::deliver($url, $payload, $event, $message, $runnerId);
    }

    public static function flushOutbox(): void
    {
        WebhookOutbox::flush(static function (string $url, array $payload): bool {
            [$ok] = self::post($url, $payload);
            return $ok;
        });
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

    /**
     * @param array<string, mixed> $payload
     */
    private static function deliver(
        string $url,
        array $payload,
        string $event,
        string $message,
        ?string $runnerId
    ): void {
        [$ok, $stderr] = self::post($url, $payload);
        if ($ok) {
            WebhookOutbox::forget($url, $event, $message, $runnerId);
            return;
        }
        WebhookOutbox::enqueue($url, $payload, $event, $message, $runnerId, $stderr);
        AppLog::errorThrottled('crash_webhook', 'failed to deliver ' . $event . ' notification', [
            'runner' => $runnerId,
            'stderr' => $stderr,
        ]);
    }

    /** @return array<string, mixed> */
    private static function payloadFor(
        string $url,
        string $event,
        string $message,
        ?string $runnerId,
        ?string $agentName,
        ?int $count = null,
        ?int $threshold = null,
        ?int $diskKb = null
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
        if ($diskKb !== null) {
            $payload['disk_kb'] = $diskKb;
            $payload['threshold_gb'] = $threshold;
        }
        return $payload;
    }
}
