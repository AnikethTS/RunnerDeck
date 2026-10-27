<?php

declare(strict_types=1);

namespace RunnerDeck\Alert;

use RunnerDeck\Core\AppLog;
use RunnerDeck\Core\Settings;

/**
 * Failed crash-webhook POSTs, retried with backoff. Success removes the
 * item so it is not sent twice. Unknown/unreadable files fail open.
 */
final class WebhookOutbox
{
    private const FILE = 'webhook_outbox.json';
    private const MAX_ITEMS = 40;
    private const MAX_ATTEMPTS = 8;

    private static ?int $nowFake = null;

    public static function fakeNow(?int $unix): void
    {
        self::$nowFake = $unix;
    }

    public static function pendingCount(): int
    {
        return count(self::load()['items']);
    }

    /**
     * Operator view: no payload, no full webhook URL.
     *
     * @return list<array{
     *     id: string,
     *     event: string,
     *     runner: ?string,
     *     message: string,
     *     attempts: int,
     *     next_at: int,
     *     created_at: int,
     *     last_error: string,
     *     host: string
     * }>
     */
    public static function pending(): array
    {
        $out = [];
        foreach (self::load()['items'] as $item) {
            $url = (string) ($item['url'] ?? '');
            $host = parse_url($url, PHP_URL_HOST);
            $out[] = [
                'id' => (string) ($item['id'] ?? ''),
                'event' => is_string($item['event'] ?? null) ? (string) $item['event'] : '',
                'runner' => is_string($item['runner'] ?? null) ? (string) $item['runner'] : null,
                'message' => is_string($item['message'] ?? null) ? (string) $item['message'] : '',
                'attempts' => (int) ($item['attempts'] ?? 0),
                'next_at' => (int) ($item['next_at'] ?? 0),
                'created_at' => (int) ($item['created_at'] ?? 0),
                'last_error' => is_string($item['last_error'] ?? null)
                    ? (string) $item['last_error']
                    : '',
                'host' => is_string($host) && $host !== '' ? $host : 'webhook',
            ];
        }
        return $out;
    }

    public static function drop(string $id): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            return false;
        }
        $data = self::load();
        $kept = [];
        foreach ($data['items'] as $item) {
            if (($item['id'] ?? '') !== $id) {
                $kept[] = $item;
            }
        }
        if (count($kept) === count($data['items'])) {
            return false;
        }
        $data['items'] = $kept;
        self::write($data);
        AppLog::info('webhook.outbox.drop', 'dropped pending webhook ' . substr($id, 0, 8));
        self::clearGaveUp();
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function enqueue(
        string $url,
        array $payload,
        string $event,
        string $message,
        ?string $runner,
        string $lastError = ''
    ): void {
        $id = self::id($url, $event, $message, $runner);
        $data = self::load();
        foreach ($data['items'] as $i => $item) {
            if (($item['id'] ?? '') === $id) {
                if ($lastError !== '') {
                    $data['items'][$i]['last_error'] = substr($lastError, 0, 240);
                    self::write($data);
                }
                return;
            }
        }
        $now = self::now();
        $data['items'][] = [
            'id' => $id,
            'url' => $url,
            'payload' => $payload,
            'event' => $event,
            'message' => $message,
            'runner' => $runner,
            'attempts' => 0,
            'next_at' => $now + 30,
            'created_at' => $now,
            'last_error' => substr($lastError, 0, 240),
        ];
        if (count($data['items']) > self::MAX_ITEMS) {
            $data['items'] = array_slice($data['items'], -self::MAX_ITEMS);
        }
        self::write($data);
    }

    public static function forget(string $url, string $event, string $message, ?string $runner): void
    {
        $id = self::id($url, $event, $message, $runner);
        $data = self::load();
        $kept = [];
        foreach ($data['items'] as $item) {
            if (($item['id'] ?? '') !== $id) {
                $kept[] = $item;
            }
        }
        if (count($kept) !== count($data['items'])) {
            $data['items'] = $kept;
            self::write($data);
        }
    }

    /** @param callable(string, array<string, mixed>): bool $send */
    public static function flush(callable $send): void
    {
        $data = self::load();
        if ($data['items'] === []) {
            return;
        }
        $now = self::now();
        $kept = [];
        foreach ($data['items'] as $item) {
            $url = (string) ($item['url'] ?? '');
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $attempts = (int) ($item['attempts'] ?? 0);
            $nextAt = (int) ($item['next_at'] ?? 0);
            if ($url === '' || $nextAt > $now) {
                $kept[] = $item;
                continue;
            }
            if ($send($url, $payload)) {
                self::clearGaveUp();
                continue;
            }
            $attempts++;
            $item['last_error'] = 'retry failed';
            if ($attempts >= self::MAX_ATTEMPTS) {
                AppLog::errorThrottled('crash_webhook', 'gave up retrying crash notification', [
                    'runner' => is_string($item['runner'] ?? null) ? (string) $item['runner'] : null,
                ]);
                self::markGaveUp(
                    (string) ($item['id'] ?? ''),
                    is_string($item['event'] ?? null) ? (string) $item['event'] : '',
                    is_string($item['runner'] ?? null) ? (string) $item['runner'] : null
                );
                continue;
            }
            $item['attempts'] = $attempts;
            $item['next_at'] = $now + self::backoffSeconds($attempts);
            $kept[] = $item;
        }
        $data['items'] = $kept;
        self::write($data);
    }

    public static function warning(): ?string
    {
        $path = self::gaveUpPath();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $at = (int) ($decoded['at'] ?? 0);
        if ($at < 1 || (self::now() - $at) > 7 * 86400) {
            return null;
        }
        $event = is_string($decoded['event'] ?? null) && $decoded['event'] !== ''
            ? (string) $decoded['event']
            : 'crash webhook';
        return 'Crash webhook gave up after max retries (' . $event
            . '). See Outbox. A later successful delivery clears this.';
    }

    public static function clearGaveUp(): void
    {
        $path = self::gaveUpPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function markGaveUp(string $id, string $event, ?string $runner): void
    {
        $dir = Settings::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $path = self::gaveUpPath();
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode([
            'at' => self::now(),
            'id' => $id,
            'event' => $event,
            'runner' => $runner,
        ], JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    private static function gaveUpPath(): string
    {
        return Settings::storageDir() . '/webhook_gave_up.json';
    }

    private static function backoffSeconds(int $attempts): int
    {
        $seconds = 30 * (2 ** max(0, $attempts - 1));
        return min(3600, $seconds);
    }

    private static function id(string $url, string $event, string $message, ?string $runner): string
    {
        return hash('sha256', $url . "\n" . $event . "\n" . ($runner ?? '') . "\n" . $message);
    }

    private static function now(): int
    {
        return self::$nowFake ?? time();
    }

    /** @return array{items: list<array<string, mixed>>} */
    private static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return ['items' => []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            return ['items' => []];
        }
        $items = [];
        foreach ($decoded['items'] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        return ['items' => $items];
    }

    /** @param array{items: list<array<string, mixed>>} $data */
    private static function write(array $data): void
    {
        $dir = Settings::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $path = self::path();
        $tmp = $path . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        file_put_contents($tmp, $json);
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }
}
