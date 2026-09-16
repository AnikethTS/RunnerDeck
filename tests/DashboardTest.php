<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private function runner(bool $running, ?float $cpu, ?int $rssKb): array
    {
        return ['local_running' => $running, 'cpu_percent' => $cpu, 'rss_kb' => $rssKb];
    }

    public function testComputeStatsOnEmptyPool(): void
    {
        $stats = \RunnerDeck\Dashboard::computeStats([]);

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['running']);
        $this->assertNull($stats['avg_cpu_percent']);
        $this->assertNull($stats['total_rss_kb']);
    }

    public function testComputeStatsCountsOnlyRunningTowardAverages(): void
    {
        $runners = [
            $this->runner(true, 10.0, 1000),
            $this->runner(true, 30.0, 2000),
            $this->runner(false, null, null),
        ];

        $stats = \RunnerDeck\Dashboard::computeStats($runners);

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['running']);
        $this->assertSame(20.0, $stats['avg_cpu_percent']);
        $this->assertSame(3000, $stats['total_rss_kb']);
    }

    public function testComputeStatsIgnoresRunningRunnersWithMissingStats(): void
    {
        $runners = [
            $this->runner(true, 40.0, 4000),
            $this->runner(true, null, null),
        ];

        $stats = \RunnerDeck\Dashboard::computeStats($runners);

        $this->assertSame(2, $stats['running']);
        $this->assertSame(40.0, $stats['avg_cpu_percent']);
        $this->assertSame(4000, $stats['total_rss_kb']);
    }
}
