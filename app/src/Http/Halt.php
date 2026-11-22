<?php

declare(strict_types=1);

namespace RunnerDeck\Http;

/**
 * Process exit used by HTTP denials. Tests replace it so the lines before
 * the exit still run under PHPUnit.
 */
final class Halt
{
    /** @var null|(callable(): never) */
    private static $handler = null;

    /** @param null|(callable(): never) $handler */
    public static function fake(?callable $handler): void
    {
        self::$handler = $handler;
    }

    public static function now(): never
    {
        if (self::$handler !== null) {
            $handler = self::$handler;
            self::$handler = null;
            $handler();
        }
        exit; // @codeCoverageIgnore
    }
}
