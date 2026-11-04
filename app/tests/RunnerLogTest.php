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
        \RunnerDeck\Pool\RunnerLog::fakeMaxBytes(20);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Pool\RunnerLog::fakeMaxBytes(null);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testLeavesSmallLogsAlone(): void
    {
        file_put_contents($this->dir . '/runner.log', 'tiny');
        \RunnerDeck\Pool\RunnerLog::rotateIfOversized($this->dir);

        $this->assertFileExists($this->dir . '/runner.log');
        $this->assertFileDoesNotExist($this->dir . '/runner.log.1');
    }

    public function testRotatesWhenOverCap(): void
    {
        file_put_contents($this->dir . '/runner.log', str_repeat('x', 32));
        file_put_contents($this->dir . '/runner.log.1', 'old');
        \RunnerDeck\Pool\RunnerLog::rotateIfOversized($this->dir);

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
            \RunnerDeck\Pool\RunnerLog::tail($this->dir, 3)
        );
    }

    public function testTailUsesOnlyRotatedWhenCurrentIsMissing(): void
    {
        file_put_contents($this->dir . '/runner.log.1', "a\nb\nc\n");

        $this->assertSame(['b', 'c'], \RunnerDeck\Pool\RunnerLog::tail($this->dir, 2));
    }

    public function testFilesAreOldestFirst(): void
    {
        file_put_contents($this->dir . '/runner.log.1', 'old');
        file_put_contents($this->dir . '/runner.log', 'new');

        $this->assertSame(
            [$this->dir . '/runner.log.1', $this->dir . '/runner.log'],
            \RunnerDeck\Pool\RunnerLog::files($this->dir)
        );
    }

    public function testTailReturnsValidUtf8ForConsoleCodepageBytes(): void
    {
        $dir = sys_get_temp_dir() . '/runnerdeck-log-utf8-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/runner.log', "plain\r\n\xFB Connected to GitHub\r\n");

        $lines = \RunnerDeck\Pool\RunnerLog::tail($dir, 5);

        unlink($dir . '/runner.log');
        rmdir($dir);
        $this->assertCount(2, $lines);
        $this->assertSame('plain', $lines[0]);
        foreach ($lines as $line) {
            $this->assertSame(1, preg_match('//u', $line));
        }
        $this->assertNotFalse(json_encode($lines));
        $this->assertStringContainsString('Connected to GitHub', $lines[1]);
    }
}
