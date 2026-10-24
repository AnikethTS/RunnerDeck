<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HostPressureTest extends TestCase
{
    private ?string $storageDir = null;

    protected function tearDown(): void
    {
        \RunnerDeck\Host\HostPressure::fakeDisk(null);
        \RunnerDeck\Host\HostPressure::fakeRam(null);
        \RunnerDeck\Core\Shell::fake(null);
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        putenv('RUNNERDECK_SETTINGS_FILE');
        if ($this->storageDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->storageDir));
        }
    }

    #[RunInSeparateProcess]
    public function testBlocksWhenDiskBelowFloor(): void
    {
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB=1');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB=0');
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [100 * 1024 * 1024, 20 * 1024 * 1024 * 1024]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 8 * 1024 * 1024);

        $snap = \RunnerDeck\Host\HostPressure::snapshot();
        $this->assertFalse($snap['ok']);
        $this->assertNotSame([], $snap['reasons']);
        $this->assertStringContainsString('disk', (string) \RunnerDeck\Host\HostPressure::blockStartMessage());
    }

    #[RunInSeparateProcess]
    public function testBlocksWhenRamBelowFloor(): void
    {
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB=0');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB=256');
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [50 * 1024 * 1024 * 1024, 100 * 1024 * 1024 * 1024]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 64 * 1024);

        $this->assertStringContainsString('RAM', (string) \RunnerDeck\Host\HostPressure::blockStartMessage());
    }

    #[RunInSeparateProcess]
    public function testUnknownReadingsFailOpen(): void
    {
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB=1');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB=256');
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [null, null]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => null);

        $this->assertTrue(\RunnerDeck\Host\HostPressure::snapshot()['ok']);
        $this->assertNull(\RunnerDeck\Host\HostPressure::blockStartMessage());
    }

    #[RunInSeparateProcess]
    public function testZeroThresholdsDisableGuard(): void
    {
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB=0');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB=0');
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [1, 100]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 1);

        $this->assertTrue(\RunnerDeck\Host\HostPressure::snapshot()['ok']);
        $this->assertNull(\RunnerDeck\Host\HostPressure::blockStartMessage());
    }

    #[RunInSeparateProcess]
    public function testCheckAlertFiresOnceThenLatches(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/runnerdeck-pressure-alert-' . uniqid();
        mkdir($this->storageDir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->storageDir . '/settings.json');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        putenv('RUNNERDECK_MIN_FREE_DISK_GIB=1');
        putenv('RUNNERDECK_MIN_FREE_RAM_MIB=0');
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [100 * 1024 * 1024, 20 * 1024 * 1024 * 1024]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 8 * 1024 * 1024);
        $posts = 0;
        \RunnerDeck\Core\Shell::fake(function () use (&$posts): array {
            $posts++;
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\Host\HostPressure::checkAlert();
        \RunnerDeck\Host\HostPressure::checkAlert();

        $this->assertSame(1, $posts);
    }
}
