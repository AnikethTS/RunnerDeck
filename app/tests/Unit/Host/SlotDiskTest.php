<?php

declare(strict_types=1);

namespace Tests\Unit\Host;

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
        \RunnerDeck\System\Shell::fake(null);
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
        \RunnerDeck\System\Shell::fake(function (array $cmd) use (&$calls): array {
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

    public function testCachedSizeStillNoticesANewWorkDirectory(): void
    {
        $storage = $this->dir . '/storage';
        mkdir($storage, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $storage . '/settings.json');

        $first = \RunnerDeck\Host\SlotDisk::inspect($this->dir);
        $this->forgetMemory();
        mkdir($this->dir . '/_work', 0755, true);
        $second = \RunnerDeck\Host\SlotDisk::inspect($this->dir);

        $this->assertFalse($first['can_clear']);
        $this->assertTrue($second['can_clear']);
        $this->assertSame($first['kb'], $second['kb']);
        \RunnerDeck\Host\SlotDisk::resetCache();
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    public function testFileCacheServesTheSameSizeWithoutMeasuringAgain(): void
    {
        $storage = $this->dir . '/storage';
        mkdir($storage, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $storage . '/settings.json');
        mkdir($this->dir . '/_work', 0755, true);
        file_put_contents($this->dir . '/_work/a', str_repeat('x', 4096));

        $first = \RunnerDeck\Host\SlotDisk::inspect($this->dir);
        $cache = new \ReflectionProperty(\RunnerDeck\Host\SlotDisk::class, 'cache');
        $cache->setValue(null, null);
        file_put_contents($this->dir . '/_work/b', str_repeat('y', 1024 * 1024));
        $second = \RunnerDeck\Host\SlotDisk::inspect($this->dir);

        $this->assertSame($first['kb'], $second['kb']);
        $this->assertTrue($second['can_clear']);
        $this->assertFileExists($storage . '/slot-disk.json');

        \RunnerDeck\Host\SlotDisk::resetCache();
        $third = \RunnerDeck\Host\SlotDisk::inspect($this->dir);
        $this->assertGreaterThan($first['kb'], $third['kb']);
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    public function testBrokenStaleAndPartialCacheFilesAreNotTrusted(): void
    {
        $storage = $this->dir . '/storage';
        mkdir($storage, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $storage . '/settings.json');
        mkdir($this->dir . '/_work', 0755, true);
        file_put_contents($this->dir . '/_work/a', str_repeat('x', 2048));
        $cacheFile = $storage . '/slot-disk.json';

        file_put_contents($cacheFile, 'not-json');
        $this->forgetMemory();
        $measured = \RunnerDeck\Host\SlotDisk::inspect($this->dir);
        $this->assertGreaterThan(0, $measured['kb']);

        file_put_contents($cacheFile, (string) json_encode([
            'at' => microtime(true) - 120,
            'byDir' => [$this->dir => ['kb' => 1, 'can_clear' => false]],
        ]));
        $this->forgetMemory();
        $fresh = \RunnerDeck\Host\SlotDisk::inspect($this->dir);
        $this->assertNotSame(1, $fresh['kb']);
        $this->assertTrue($fresh['can_clear']);

        file_put_contents($cacheFile, (string) json_encode([
            'at' => microtime(true),
            'byDir' => [
                'skip-me' => 'not-a-row',
                $this->dir => ['kb' => 'nope', 'can_clear' => true],
            ],
        ]));
        $this->forgetMemory();
        $partial = \RunnerDeck\Host\SlotDisk::inspect($this->dir);
        $this->assertNull($partial['kb']);
        $this->assertTrue($partial['can_clear']);

        \RunnerDeck\Host\SlotDisk::resetCache();
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    public function testPersistDropsACacheThatCannotBeEncoded(): void
    {
        $storage = $this->dir . '/storage';
        mkdir($storage, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $storage . '/settings.json');
        $cache = new \ReflectionProperty(\RunnerDeck\Host\SlotDisk::class, 'cache');
        $cache->setValue(null, [
            'at' => microtime(true),
            'byDir' => ["\xC3\x28" => ['kb' => 1, 'can_clear' => false]],
        ]);
        $persist = new \ReflectionMethod(\RunnerDeck\Host\SlotDisk::class, 'persist');
        $persist->invoke(null);

        $this->assertFileDoesNotExist($storage . '/slot-disk.json');
        \RunnerDeck\Host\SlotDisk::resetCache();
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    public function testPersistKeepsGoingWhenTheCacheFileCannotBeWritten(): void
    {
        $storage = $this->dir . '/storage';
        mkdir($storage, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $storage . '/settings.json');
        mkdir($this->dir . '/_work', 0755, true);
        file_put_contents($this->dir . '/_work/a', 'x');
        // A directory where the temp file should go. Root ignores a read-only
        // mode bit, so chmod cannot stand in for a failed write.
        mkdir($storage . '/slot-disk.json.' . getmypid() . '.tmp', 0700, true);
        try {
            $info = \RunnerDeck\Host\SlotDisk::inspect($this->dir);
            $this->assertGreaterThan(0, $info['kb']);
            $this->assertFileDoesNotExist($storage . '/slot-disk.json');
        } finally {
            \RunnerDeck\Host\SlotDisk::resetCache();
            putenv('RUNNERDECK_SETTINGS_FILE');
        }
    }

    public function testPersistRemovesTheTempFileWhenRenameFails(): void
    {
        $storage = $this->dir . '/storage';
        mkdir($storage, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $storage . '/settings.json');
        mkdir($storage . '/slot-disk.json/keep', 0700, true);
        mkdir($this->dir . '/_work', 0755, true);
        file_put_contents($this->dir . '/_work/a', 'x');

        $info = \RunnerDeck\Host\SlotDisk::inspect($this->dir);

        $this->assertGreaterThan(0, $info['kb']);
        $this->assertSame([], glob($storage . '/slot-disk.json.*.tmp') ?: []);
        $this->assertDirectoryExists($storage . '/slot-disk.json');
        \RunnerDeck\Host\SlotDisk::resetCache();
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    private function forgetMemory(): void
    {
        $cache = new \ReflectionProperty(\RunnerDeck\Host\SlotDisk::class, 'cache');
        $cache->setValue(null, null);
    }

    public function testFormatKbUsesGbThenMbThenKb(): void
    {
        $this->assertSame('—', \RunnerDeck\Host\SlotDisk::formatKb(null));
        $this->assertSame('500 KB', \RunnerDeck\Host\SlotDisk::formatKb(500));
        $this->assertSame('2 MB', \RunnerDeck\Host\SlotDisk::formatKb(2048));
        $this->assertSame('1.0 GB', \RunnerDeck\Host\SlotDisk::formatKb(1024 * 1024));
    }
}
