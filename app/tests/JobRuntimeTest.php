<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class JobRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        \RunnerDeck\Host\JobRuntime::fake(null);
    }

    public function testLineNamesTheNodeJobsInherit(): void
    {
        \RunnerDeck\Host\JobRuntime::fake(fn () => 'v20.20.2');

        $this->assertSame('v20.20.2', \RunnerDeck\Host\JobRuntime::version());
        $this->assertSame('node v20.20.2 — jobs inherit this', \RunnerDeck\Host\JobRuntime::line());
    }

    public function testLineSaysWhenNodeIsMissing(): void
    {
        \RunnerDeck\Host\JobRuntime::fake(fn () => null);

        $this->assertSame('node is not on PATH — jobs inherit this', \RunnerDeck\Host\JobRuntime::line());
    }
}
