<?php

declare(strict_types=1);

namespace RunnerDeck\Alert;

use RunnerDeck\Core\Config;
use RunnerDeck\Core\Settings;
use RunnerDeck\GitHub\QueuedJobs;

final class QueueAlert
{
    private const FILE = 'queue_alert.json';

    /**
     * One-shot webhook when any queued job has waited at least N minutes.
     *
     * @param list<array<string, mixed>> $jobs
     */
    public static function check(array $jobs): void
    {
        $minutes = Config::queueWaitMinutes();
        if ($minutes === null || !CrashWebhook::wantsNotify()) {
            return;
        }
        $need = $minutes * 60;
        $worst = 0;
        $name = '';
        foreach ($jobs as $job) {
            $sec = (int) ($job['waited_sec'] ?? 0);
            if ($sec > $worst) {
                $worst = $sec;
                $name = (string) ($job['name'] ?? 'job');
            }
        }
        $over = $worst >= $need;
        $state = self::read();
        $was = !empty($state['fired']);
        if ($over && !$was) {
            CrashWebhook::notifyEvent(
                'queue_wait',
                'RunnerDeck: job "' . $name . '" has waited ' . QueuedJobs::formatWait($worst)
                    . ' on ' . Config::label() . ' (threshold ' . $minutes . ' min).'
            );
        }
        self::write(['fired' => $over]);
    }

    /** @return array{fired?: bool} */
    private static function read(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array{fired: bool} $state */
    private static function write(array $state): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = self::path() . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, self::path());
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/' . self::FILE;
    }
}
