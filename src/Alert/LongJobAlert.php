<?php

declare(strict_types=1);

namespace RunnerDeck\Alert;

use RunnerDeck\Core\Config;
use RunnerDeck\Core\Settings;
use RunnerDeck\GitHub\QueuedJobs;

final class LongJobAlert
{
    private const FILE = 'long_job_alert.json';

    /**
     * One-shot webhook per in-progress job that has run at least N minutes.
     *
     * @param list<array<string, mixed>> $live
     */
    public static function check(array $live): void
    {
        $minutes = Config::longJobMinutes();
        if ($minutes === null || !CrashWebhook::wantsNotify()) {
            return;
        }
        $need = $minutes * 60;
        $state = self::read();
        $fired = [];
        foreach (is_array($state['fired_ids'] ?? null) ? $state['fired_ids'] : [] as $id) {
            $fired[(int) $id] = true;
        }
        $still = [];
        foreach ($live as $job) {
            $id = (int) ($job['id'] ?? 0);
            $sec = (int) ($job['duration_sec'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $over = $sec >= $need;
            if ($over) {
                $still[$id] = true;
                if (empty($fired[$id])) {
                    $name = (string) ($job['name'] ?? 'job');
                    $runner = (string) ($job['runner_name'] ?? '');
                    CrashWebhook::notifyEvent(
                        'long_job',
                        'RunnerDeck: job "' . $name . '" has been running '
                            . QueuedJobs::formatWait($sec)
                            . ($runner !== '' ? ' on ' . $runner : '')
                            . ' (threshold ' . $minutes . ' min).'
                    );
                }
            }
        }
        self::write(['fired_ids' => array_map('intval', array_keys($still))]);
    }

    /** @return array<string, mixed> */
    private static function read(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array{fired_ids: list<int>} $state */
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
