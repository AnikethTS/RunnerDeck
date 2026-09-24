<?php

declare(strict_types=1);

namespace RunnerDeck;

final class SlotDisk
{
    /** @var array{at: float, byDir: array<string, array{kb: ?int, can_clear: bool}>}|null */
    private static ?array $cache = null;

    /**
     * Best-effort size of `_work`, `_diag`, and `runner.log`.
     *
     * @return array{kb: ?int, can_clear: bool}
     */
    public static function inspect(string $dir): array
    {
        $now = microtime(true);
        if (self::$cache !== null && ($now - self::$cache['at']) < 8.0 && isset(self::$cache['byDir'][$dir])) {
            return self::$cache['byDir'][$dir];
        }

        $canClear = is_dir($dir . '/_work');
        $paths = [];
        foreach (['_work', '_diag'] as $sub) {
            $path = $dir . '/' . $sub;
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }
        $log = $dir . '/runner.log';
        if (is_file($log)) {
            $paths[] = $log;
        }

        $kb = 0;
        if ($paths !== []) {
            $result = Shell::exec(array_merge(['du', '-sk'], $paths), 8);
            $kb = self::parseDuKb($result);
        }

        $info = ['kb' => $kb, 'can_clear' => $canClear];
        if (self::$cache === null || ($now - self::$cache['at']) >= 8.0) {
            self::$cache = ['at' => $now, 'byDir' => []];
        }
        self::$cache['byDir'][$dir] = $info;
        return $info;
    }

    public static function clearWork(string $dir): array
    {
        $work = $dir . '/_work';
        if (!is_dir($work)) {
            return ['ok' => true, 'message' => 'no work directory to clear'];
        }
        $result = Shell::exec(['rm', '-rf', $work], 60);
        if ($result['code'] !== 0) {
            $detail = trim($result['stderr']);
            $message = 'failed to clear work dir' . ($detail !== '' ? ': ' . $detail : '');
            return ['ok' => false, 'message' => $message];
        }
        self::$cache = null;
        return ['ok' => true, 'message' => 'work directory cleared'];
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /**
     * @param array{code: int, stdout: string, stderr: string} $result
     */
    private static function parseDuKb(array $result): ?int
    {
        if ($result['code'] !== 0) {
            return null;
        }
        $sum = 0;
        $parsed = false;
        foreach (explode("\n", trim($result['stdout'])) as $line) {
            if (preg_match('/^(\d+)\s+/', $line, $m) === 1) {
                $sum += (int) $m[1];
                $parsed = true;
            }
        }
        return $parsed ? $sum : null;
    }
}
