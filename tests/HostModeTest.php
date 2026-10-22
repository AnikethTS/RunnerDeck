<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HostModeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-hostmode-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testMaintenanceRoundTrip(): void
    {
        $this->assertFalse(\RunnerDeck\HostMode::isMaintenance());
        $this->assertNull(\RunnerDeck\HostMode::blockStartMessage());
        \RunnerDeck\HostMode::setMaintenance(true);
        $this->assertTrue(\RunnerDeck\HostMode::isMaintenance());
        $this->assertNotNull(\RunnerDeck\HostMode::blockStartMessage());
        \RunnerDeck\HostMode::setMaintenance(false);
        $this->assertFalse(\RunnerDeck\HostMode::isMaintenance());
    }

    #[RunInSeparateProcess]
    public function testRebootReadyMergesAndMaintenanceClearsIt(): void
    {
        $this->assertFalse(\RunnerDeck\HostMode::isRebootReady());
        \RunnerDeck\HostMode::setRebootReady(true);
        $this->assertTrue(\RunnerDeck\HostMode::isRebootReady());
        $this->assertFalse(\RunnerDeck\HostMode::isMaintenance());
        \RunnerDeck\HostMode::setMaintenance(true);
        $this->assertTrue(\RunnerDeck\HostMode::isMaintenance());
        $this->assertFalse(\RunnerDeck\HostMode::isRebootReady());
    }
}
