<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-metrics-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        mkdir($this->dir . '/pool', 0755, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [5 * 1024 * 1024 * 1024, 10 * 1024 * 1024 * 1024]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 1024 * 1024);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [false, null]);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Host\HostPressure::fakeDisk(null);
        \RunnerDeck\Host\HostPressure::fakeRam(null);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(null);
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testTextIsPrometheusAndDoesNotNeedGitHub(): void
    {
        file_put_contents($this->dir . '/queue_cache.json', json_encode([
            'fetched_at' => 1,
            'payload' => ['count' => 3, 'recent' => []],
        ]));
        $text = \RunnerDeck\Host\Metrics::text();
        $this->assertStringContainsString('runnerdeck_runners{state="total"} 0', $text);
        $this->assertStringContainsString('runnerdeck_queue_jobs 3', $text);
        $this->assertStringContainsString('runnerdeck_webhook_outbox 0', $text);
        $this->assertStringContainsString('runnerdeck_host_pressure 0', $text);
        $this->assertStringContainsString('# TYPE runnerdeck_maintenance gauge', $text);
    }
}
