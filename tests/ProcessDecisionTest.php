<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ProcessDecisionTest extends TestCase
{
    public function testStartUsesPidfileWhenAlive(): void
    {
        $result = \ProcessDecision::resolveStart([true, 42], ['/runners/r1' => 99], '/runners/r1');

        $this->assertTrue($result['running']);
        $this->assertSame(42, $result['pid']);
        $this->assertFalse($result['rewrite_pidfile']);
    }

    public function testStartRewritesPidfileFromLiveListener(): void
    {
        $result = \ProcessDecision::resolveStart([false, 7], ['/runners/r1' => 99], '/runners/r1');

        $this->assertTrue($result['running']);
        $this->assertSame(99, $result['pid']);
        $this->assertTrue($result['rewrite_pidfile']);
    }

    public function testStartSpawnsWhenNothingIsLive(): void
    {
        $result = \ProcessDecision::resolveStart([false, null], [], '/runners/r1');

        $this->assertFalse($result['running']);
        $this->assertNull($result['pid']);
        $this->assertFalse($result['rewrite_pidfile']);
    }

    public function testStopUsesPidfileWhenAlive(): void
    {
        $result = \ProcessDecision::resolveStop([true, 42], ['/runners/r1' => 99], '/runners/r1');

        $this->assertTrue($result['running']);
        $this->assertSame(42, $result['pid']);
    }

    public function testStopFallsBackToLiveListener(): void
    {
        $result = \ProcessDecision::resolveStop([false, 7], ['/runners/r1' => 99], '/runners/r1');

        $this->assertTrue($result['running']);
        $this->assertSame(99, $result['pid']);
    }

    public function testStopNotRunningKeepsStalePidfilePid(): void
    {
        $result = \ProcessDecision::resolveStop([false, 7], [], '/runners/r1');

        $this->assertFalse($result['running']);
        $this->assertSame(7, $result['pid']);
    }

    public function testParseSpawnedPidTrimsStdout(): void
    {
        $this->assertSame(1234, \ProcessDecision::parseSpawnedPid("1234\n"));
        $this->assertSame(0, \ProcessDecision::parseSpawnedPid(""));
        $this->assertSame(0, \ProcessDecision::parseSpawnedPid("not-a-pid"));
    }
}
