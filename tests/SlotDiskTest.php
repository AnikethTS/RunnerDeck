<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class SlotDiskTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-disk-' . uniqid();
        mkdir($this->dir, 0755, true);
        \RunnerDeck\Host\SlotDisk::resetCache();
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Host\SlotDisk::resetCache();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testInspectReportsZeroWhenNothingToMeasure(): void
    {
        $info = \RunnerDeck\Host\SlotDisk::inspect($this->dir);

        $this->assertSame(0, $info['kb']);
        $this->assertFalse($info['can_clear']);
    }

    public function testInspectCanClearWhenWorkDirExists(): void
    {
        mkdir($this->dir . '/_work', 0755, true);
        file_put_contents($this->dir . '/_work/a', str_repeat('x', 4096));

        $info = \RunnerDeck\Host\SlotDisk::inspect($this->dir);

        $this->assertTrue($info['can_clear']);
        $this->assertNotNull($info['kb']);
        $this->assertGreaterThan(0, $info['kb']);
    }

    public function testClearWorkRemovesWorkDir(): void
    {
        mkdir($this->dir . '/_work/nested', 0755, true);
        file_put_contents($this->dir . '/_work/nested/a', 'x');

        $result = \RunnerDeck\Host\SlotDisk::clearWork($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertDirectoryDoesNotExist($this->dir . '/_work');
    }

    public function testFormatKbUsesGbThenMbThenKb(): void
    {
        $this->assertSame('—', \RunnerDeck\Host\SlotDisk::formatKb(null));
        $this->assertSame('500 KB', \RunnerDeck\Host\SlotDisk::formatKb(500));
        $this->assertSame('2 MB', \RunnerDeck\Host\SlotDisk::formatKb(2048));
        $this->assertSame('1.0 GB', \RunnerDeck\Host\SlotDisk::formatKb(1024 * 1024));
    }
}
