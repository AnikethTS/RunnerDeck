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
        \RunnerDeck\Shell::fake(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
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

    #[RunInSeparateProcess]
    public function testMaintenanceWebhookFiresOnceOnRisingEdge(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $bodies = [];
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$bodies): array {
            if (($cmd[0] ?? '') === 'curl') {
                $bodies[] = $cmd[array_search('-d', $cmd, true) + 1];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\HostMode::setMaintenance(true);
        \RunnerDeck\HostMode::setMaintenance(true);
        $this->assertCount(1, $bodies);
        $decoded = json_decode((string) $bodies[0], true);
        $this->assertSame('maintenance', $decoded['event']);

        \RunnerDeck\HostMode::setMaintenance(false);
        \RunnerDeck\HostMode::setRebootReady(true);
        \RunnerDeck\HostMode::setRebootReady(true);
        $this->assertCount(2, $bodies);
        $decoded = json_decode((string) $bodies[1], true);
        $this->assertSame('reboot_ready', $decoded['event']);
    }
}
