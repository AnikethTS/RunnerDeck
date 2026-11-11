<?php

declare(strict_types=1);

namespace RunnerDeck\Host;

use RunnerDeck\System\Shell;

/** The Node binary a job will see. Runners do not install their own Node. */
final class JobRuntime
{
    private static bool $resolved = false;

    private static ?string $cached = null;

    /** @var (callable(): ?string)|null */
    private static $fake = null;

    /** @param (callable(): ?string)|null $handler */
    public static function fake(?callable $handler): void
    {
        self::$fake = $handler;
        self::$resolved = false;
        self::$cached = null;
    }

    public static function version(): ?string
    {
        if (self::$fake !== null) {
            return (self::$fake)();
        }
        if (self::$resolved) {
            return self::$cached;
        }
        self::$resolved = true;
        $result = Shell::exec(['node', '-v'], 3);
        $line = trim($result['stdout']);
        if ($result['code'] !== 0 || preg_match('/^v\d+\.\d+\.\d+/', $line) !== 1) {
            return self::$cached = null;
        }
        return self::$cached = $line;
    }

    public static function line(): string
    {
        $version = self::version();
        if ($version === null) {
            return 'node is not on PATH — jobs inherit this';
        }
        return 'node ' . $version . ' — jobs inherit this';
    }
}
