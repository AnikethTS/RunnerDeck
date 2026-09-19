<?php

declare(strict_types=1);

namespace RunnerDeck;

final class CrashState
{
    private const STATE_FILE = 'crash_state.json';
    private const MAX_ATTEMPTS = 3;
    private const HEALTHY_RESET_SECONDS = 120;
    private const MISMATCH_THRESHOLD = 3;

    private static function statePath(): string
    {
        return Settings::storageDir() . '/' . self::STATE_FILE;
    }

    /** @return array<string, array<string, mixed>> */
    private static function readState(): array
    {
        $path = self::statePath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($path) ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string, mixed>> $data */
    private static function writeState(array $data): void
    {
        $path = self::statePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    public static function markExplicitlyStopped(string $id): void
    {
        $state = self::readState();
        $state[$id] = self::runnerState($state, $id);
        $state[$id]['justStopped'] = true;
        self::writeState($state);
    }

    /** @param array<string, array<string, mixed>> $state */
    private static function runnerState(array $state, string $id): array
    {
        return $state[$id] ?? [
            'wasRunning' => false, 'attempts' => 0, 'healthySince' => 0,
            'flagged' => false, 'notified' => false, 'justStopped' => false,
            'mismatchStreak' => 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $runners each with at least id/local_running/configured
     * @return array<int, array<string, mixed>> same runners, with crash/mismatch flags added
     */
    public static function track(array $runners): array
    {
        $state = self::readState();
        $autoRestart = Config::autoRestartEnabled();

        foreach ($runners as &$r) {
            $s = self::runnerState($state, $r['id']);

            if ($r['local_running']) {
                if (!$s['healthySince']) {
                    $s['healthySince'] = time();
                }
                if (time() - $s['healthySince'] > self::HEALTHY_RESET_SECONDS) {
                    $s['attempts'] = 0;
                    $s['flagged'] = false;
                    $s['notified'] = false;
                }
            } else {
                $s['healthySince'] = 0;
            }

            $crashed = $s['wasRunning'] && !$r['local_running'] && $r['configured'] && !$s['justStopped'];
            $shouldAutoRestart = false;
            if ($crashed) {
                if ($autoRestart && $s['attempts'] < self::MAX_ATTEMPTS) {
                    $s['attempts']++;
                    $shouldAutoRestart = true;
                } else {
                    $s['flagged'] = true;
                }
            }

            $justFlagged = $s['flagged'] && !$s['notified'];
            if ($justFlagged) {
                $s['notified'] = true;
            }

            $ghOnline = is_array($r['github'] ?? null) && ($r['github']['status'] ?? '') === 'online';
            $mismatched = is_array($r['github'] ?? null) && $ghOnline !== (bool) $r['local_running'];
            $s['mismatchStreak'] = $mismatched ? ((int) ($s['mismatchStreak'] ?? 0)) + 1 : 0;

            $s['justStopped'] = false;
            $s['wasRunning'] = $r['local_running'];
            $state[$r['id']] = $s;

            $r['crash_flagged'] = $s['flagged'];
            $r['just_flagged'] = $justFlagged;
            $r['should_auto_restart'] = $shouldAutoRestart;
            $r['mismatch_flagged'] = $s['mismatchStreak'] >= self::MISMATCH_THRESHOLD;
        }

        self::writeState($state);
        return $runners;
    }
}
