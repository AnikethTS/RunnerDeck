<?php

declare(strict_types=1);

namespace RunnerDeck;

final class Drain
{
    /** @var (callable(int): void)|null */
    private static $sleepFake = null;

    /** @var (callable(): int)|null */
    private static $nowFake = null;

    /** @param (callable(int): void)|null $handler */
    public static function fakeSleep(?callable $handler): void
    {
        self::$sleepFake = $handler;
    }

    /** @param (callable(): int)|null $handler */
    public static function fakeNow(?callable $handler): void
    {
        self::$nowFake = $handler;
    }

    /**
     * @return array{ok: bool, message?: string, busy?: bool, busy_unknown?: bool, timed_out?: bool}
     */
    public static function waitUntilIdle(string $agentName, int $timeoutSeconds, int $pollSeconds = 2): array
    {
        $timeoutSeconds = max(0, $timeoutSeconds);
        $deadline = self::now() + $timeoutSeconds;
        while (true) {
            [$known, $busy, $error] = Dashboard::isBusy($agentName);
            if (!$known) {
                return [
                    'ok' => false,
                    'busy_unknown' => true,
                    'message' => "Could not verify job status via GitHub API ({$error})."
                        . ' Pass force=1 to stop anyway.',
                ];
            }
            if (!$busy) {
                return ['ok' => true];
            }
            $remaining = $deadline - self::now();
            if ($remaining <= 0) {
                return [
                    'ok' => false,
                    'busy' => true,
                    'timed_out' => true,
                    'message' => "{$agentName} is still running a job after {$timeoutSeconds}s."
                        . ' Pass force=1 to stop anyway.',
                ];
            }
            self::sleep(min(max(1, $pollSeconds), $remaining));
        }
    }

    /**
     * @param RunnerInfo[] $runners
     * @return array{ok: bool, message?: string, busy?: bool, busy_unknown?: bool,
     *   busy_runners?: list<string>, timed_out?: bool}|array{ok: true}
     */
    public static function waitUntilNoneBusy(array $runners, int $timeoutSeconds, int $pollSeconds = 2): array
    {
        $timeoutSeconds = max(0, $timeoutSeconds);
        $deadline = self::now() + $timeoutSeconds;
        while (true) {
            $blocked = Api::checkNoneBusy($runners, false);
            if ($blocked === null) {
                return ['ok' => true];
            }
            if (!empty($blocked['busy_unknown'])) {
                return [
                    'ok' => false,
                    'busy_unknown' => true,
                    'message' => (string) ($blocked['message'] ?? ''),
                ];
            }
            $remaining = $deadline - self::now();
            if ($remaining <= 0) {
                $names = [];
                foreach ($blocked['busy_runners'] ?? [] as $name) {
                    $names[] = (string) $name;
                }
                return [
                    'ok' => false,
                    'busy' => true,
                    'timed_out' => true,
                    'busy_runners' => $names,
                    'message' => (string) ($blocked['message'] ?? 'Busy runners would be interrupted.')
                        . " Still busy after {$timeoutSeconds}s.",
                ];
            }
            self::sleep(min(max(1, $pollSeconds), $remaining));
        }
    }

    private static function now(): int
    {
        return self::$nowFake !== null ? (self::$nowFake)() : time();
    }

    private static function sleep(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (self::$sleepFake !== null) {
            (self::$sleepFake)($seconds);
            return;
        }
        sleep($seconds);
    }
}
