<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class QueueAlertTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-queue-alert-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_LABEL=self-hosted-runnerdeck');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        putenv('RUNNERDECK_LABEL');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testFiresOnceWhenAJobExceedsTheWait(): void
    {
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES=1');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $bodies = [];
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$bodies): array {
            $bodies[] = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $jobs = [['name' => 'ci', 'waited_sec' => 120]];
        \RunnerDeck\QueueAlert::check($jobs);
        \RunnerDeck\QueueAlert::check($jobs);

        $this->assertCount(1, $bodies);
        $decoded = json_decode((string) $bodies[0], true);
        $this->assertSame('queue_wait', $decoded['event']);
        $this->assertStringContainsString('ci', $decoded['message']);
    }

    #[RunInSeparateProcess]
    public function testDoesNotFireWhenUnderThreshold(): void
    {
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES=10');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $called = false;
        \RunnerDeck\Shell::fake(function () use (&$called): array {
            $called = true;
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\QueueAlert::check([['name' => 'ci', 'waited_sec' => 30]]);

        $this->assertFalse($called);
    }
}
