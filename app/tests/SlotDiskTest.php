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
        \RunnerDeck\Core\Shell::fake(null);
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

    public function testPrimeMeasuresEverySlotInOneDu(): void
    {
        $a = $this->dir . '/a';
        $b = $this->dir . '/b';
        mkdir($a . '/_work', 0755, true);
        mkdir($b . '/_work', 0755, true);
        file_put_contents($a . '/runner.log', 'log');
        $calls = 0;
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$calls): array {
            $calls++;
            $out = '';
            foreach (array_slice($cmd, 2) as $path) {
                $out .= "10\t{$path}\n";
            }
            return ['code' => 0, 'stdout' => $out, 'stderr' => ''];
        });

        \RunnerDeck\Host\SlotDisk::prime([$a, $b]);

        $this->assertSame(1, $calls);
        $this->assertSame(20, \RunnerDeck\Host\SlotDisk::inspect($a)['kb']);
        $this->assertSame(10, \RunnerDeck\Host\SlotDisk::inspect($b)['kb']);
        $this->assertTrue(\RunnerDeck\Host\SlotDisk::inspect($a)['can_clear']);
        $this->assertSame(1, $calls);
    }

    public function testFormatKbUsesGbThenMbThenKb(): void
    {
        $this->assertSame('—', \RunnerDeck\Host\SlotDisk::formatKb(null));
        $this->assertSame('500 KB', \RunnerDeck\Host\SlotDisk::formatKb(500));
        $this->assertSame('2 MB', \RunnerDeck\Host\SlotDisk::formatKb(2048));
        $this->assertSame('1.0 GB', \RunnerDeck\Host\SlotDisk::formatKb(1024 * 1024));
    }
}
