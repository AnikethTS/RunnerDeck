<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SystemStatsTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testSnapshotReportsCoresAndMemoryShape(): void
    {
        $snap = \RunnerDeck\Host\SystemStats::snapshot();

        $this->assertGreaterThanOrEqual(1, $snap['cpu_cores']);
        $this->assertIsInt($snap['mem_used_kb']);
        $this->assertGreaterThanOrEqual(0, $snap['mem_used_kb']);
        if ($snap['cpu_percent'] !== null) {
            $this->assertIsFloat($snap['cpu_percent']);
            $this->assertGreaterThanOrEqual(0.0, $snap['cpu_percent']);
            $this->assertLessThanOrEqual(100.0, $snap['cpu_percent']);
        }
        if ($snap['mem_percent'] !== null) {
            $this->assertIsFloat($snap['mem_percent']);
            $this->assertGreaterThanOrEqual(0.0, $snap['mem_percent']);
            $this->assertLessThanOrEqual(100.0, $snap['mem_percent']);
        }
        if ($snap['mem_total_kb'] !== null) {
            $this->assertGreaterThan(0, $snap['mem_total_kb']);
        }
    }

    #[RunInSeparateProcess]
    public function testLinuxReadsMemTotalFromProc(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/meminfo')) {
            $this->markTestSkipped('needs Linux /proc/meminfo');
        }

        $snap = \RunnerDeck\Host\SystemStats::snapshot();
        $this->assertNotNull($snap['mem_total_kb']);

        $meminfo = (string) file_get_contents('/proc/meminfo');
        $this->assertSame(1, preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $meminfo, $m));
        $this->assertSame((int) $m[1], $snap['mem_total_kb']);
    }
}
