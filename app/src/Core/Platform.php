<?php

declare(strict_types=1);

namespace RunnerDeck\Core;

final class Platform
{
    private static ?bool $windowsFake = null;

    /** Test seam. */
    public static function fakeWindows(?bool $value): void
    {
        self::$windowsFake = $value;
    }

    public static function isWindows(): bool
    {
        return self::$windowsFake ?? PHP_OS_FAMILY === 'Windows';
    }

    public static function runnerScript(string $name): string
    {
        return $name . (self::isWindows() ? '.cmd' : '.sh');
    }

    public static function pathKey(string $path): string
    {
        if (!self::isWindows()) {
            return $path;
        }
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    public static function home(): string
    {
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $home = getenv('USERPROFILE');
        }
        return is_string($home) ? $home : '';
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function powershell(string $script, int $timeoutSec = 15, ?string $cwd = null): array
    {
        return Shell::exec(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script],
            $timeoutSec,
            $cwd
        );
    }

    public static function commandExists(string $bin): bool
    {
        return Shell::exec([self::isWindows() ? 'where' : 'which', $bin], 5)['code'] === 0;
    }

    // cmd.exe re-parses batch arguments, so & | % ^ " in a name could run commands.
    public static function isSafeBatchArg(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._,:\/-]*$/', $value) === 1;
    }
}
