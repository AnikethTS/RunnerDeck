<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class RunnerLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-rlog-' . uniqid();
        mkdir($this->dir, 0755, true);
        \RunnerDeck\RunnerLog::fakeMaxBytes(20);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\RunnerLog::fakeMaxBytes(null);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testLeavesSmallLogsAlone(): void
    {
        file_put_contents($this->dir . '/runner.log', 'tiny');
        \RunnerDeck\RunnerLog::rotateIfOversized($this->dir);

        $this->assertFileExists($this->dir . '/runner.log');
        $this->assertFileDoesNotExist($this->dir . '/runner.log.1');
    }

    public function testRotatesWhenOverCap(): void
    {
        file_put_contents($this->dir . '/runner.log', str_repeat('x', 32));
        file_put_contents($this->dir . '/runner.log.1', 'old');
        \RunnerDeck\RunnerLog::rotateIfOversized($this->dir);

        $this->assertFileDoesNotExist($this->dir . '/runner.log');
        $this->assertFileExists($this->dir . '/runner.log.1');
        $this->assertSame(str_repeat('x', 32), (string) file_get_contents($this->dir . '/runner.log.1'));
    }

    public function testTailPrefersCurrentThenFillsFromRotated(): void
    {
        file_put_contents($this->dir . '/runner.log.1', "old-1\nold-2\nold-3\n");
        file_put_contents($this->dir . '/runner.log', "new-1\nnew-2\n");

        $this->assertSame(
            ['old-3', 'new-1', 'new-2'],
            \RunnerDeck\RunnerLog::tail($this->dir, 3)
        );
    }

    public function testTailUsesOnlyRotatedWhenCurrentIsMissing(): void
    {
        file_put_contents($this->dir . '/runner.log.1', "a\nb\nc\n");

        $this->assertSame(['b', 'c'], \RunnerDeck\RunnerLog::tail($this->dir, 2));
    }

    public function testFilesAreOldestFirst(): void
    {
        file_put_contents($this->dir . '/runner.log.1', 'old');
        file_put_contents($this->dir . '/runner.log', 'new');

        $this->assertSame(
            [$this->dir . '/runner.log.1', $this->dir . '/runner.log'],
            \RunnerDeck\RunnerLog::files($this->dir)
        );
    }
}
