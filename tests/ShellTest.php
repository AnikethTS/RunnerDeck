<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ShellTest extends TestCase
{
    public function testCapturesStdoutAndExitCode(): void
    {
        $result = \Shell::exec([PHP_BINARY, '-r', 'echo "hello"; exit(0);'], 5);

        $this->assertSame(0, $result['code']);
        $this->assertSame('hello', $result['stdout']);
    }

    public function testCapturesNonZeroExitCode(): void
    {
        $result = \Shell::exec([PHP_BINARY, '-r', 'exit(3);'], 5);

        $this->assertSame(3, $result['code']);
    }

    public function testKillsProcessOnTimeout(): void
    {
        $result = \Shell::exec([PHP_BINARY, '-r', 'sleep(5);'], 1);

        $this->assertNotSame(0, $result['code']);
        $this->assertStringContainsString('timed out', $result['stderr']);
    }
}
