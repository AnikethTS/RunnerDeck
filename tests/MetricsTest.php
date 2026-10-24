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
        \RunnerDeck\HostPressure::fakeDisk(fn () => [5 * 1024 * 1024 * 1024, 10 * 1024 * 1024 * 1024]);
        \RunnerDeck\HostPressure::fakeRam(fn () => 1024 * 1024);
        \RunnerDeck\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [false, null]);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\HostPressure::fakeDisk(null);
        \RunnerDeck\HostPressure::fakeRam(null);
        \RunnerDeck\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\RunnerPool::fakeCheckProcess(null);
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
        $text = \RunnerDeck\Metrics::text();
        $this->assertStringContainsString('runnerdeck_runners{state="total"} 0', $text);
        $this->assertStringContainsString('runnerdeck_queue_jobs 3', $text);
        $this->assertStringContainsString('runnerdeck_webhook_outbox 0', $text);
        $this->assertStringContainsString('runnerdeck_host_pressure 0', $text);
        $this->assertStringContainsString('# TYPE runnerdeck_maintenance gauge', $text);
    }
}
