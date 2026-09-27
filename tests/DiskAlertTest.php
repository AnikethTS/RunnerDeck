<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DiskAlertTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-diskalert-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        putenv('RUNNERDECK_DISK_WEBHOOK_THRESHOLD=1');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        putenv('RUNNERDECK_DISK_WEBHOOK_THRESHOLD');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, mixed> */
    private function runner(int $diskKb): array
    {
        return [
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'disk_kb' => $diskKb,
        ];
    }

    #[RunInSeparateProcess]
    public function testFiresOnceWhenSlotCrossesThreshold(): void
    {
        $bodies = [];
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$bodies): array {
            $bodies[] = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $over = \RunnerDeck\DiskAlert::KIB_PER_GIB;
        \RunnerDeck\DiskAlert::check([$this->runner($over)], $over);
        \RunnerDeck\DiskAlert::check([$this->runner($over + 10)], $over + 10);

        $this->assertCount(2, $bodies);
        $slot = json_decode((string) $bodies[0], true);
        $this->assertSame('disk_slot', $slot['event']);
        $pool = json_decode((string) $bodies[1], true);
        $this->assertSame('disk_pool', $pool['event']);
    }

    #[RunInSeparateProcess]
    public function testDoesNotFireAgainUntilUsageDropsBelowThreshold(): void
    {
        $n = 0;
        \RunnerDeck\Shell::fake(function () use (&$n): array {
            $n++;
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $over = \RunnerDeck\DiskAlert::KIB_PER_GIB;
        \RunnerDeck\DiskAlert::check([$this->runner($over)], $over);
        $this->assertSame(2, $n);

        \RunnerDeck\DiskAlert::check([$this->runner($over)], $over);
        $this->assertSame(2, $n);

        \RunnerDeck\DiskAlert::check([$this->runner(1)], 1);
        \RunnerDeck\DiskAlert::check([$this->runner($over)], $over);
        $this->assertSame(4, $n);
    }
}
