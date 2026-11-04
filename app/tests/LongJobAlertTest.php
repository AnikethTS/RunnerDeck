<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class LongJobAlertTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-long-job-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Core\Shell::fake(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_LONG_JOB_MINUTES');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testFiresOncePerJobThenClearsWhenGone(): void
    {
        putenv('RUNNERDECK_LONG_JOB_MINUTES=1');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $bodies = [];
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$bodies): array {
            $bodies[] = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $job = ['id' => 9, 'name' => 'lint', 'runner_name' => 'acme-1', 'duration_sec' => 120];
        \RunnerDeck\Alert\LongJobAlert::check([$job]);
        \RunnerDeck\Alert\LongJobAlert::check([$job]);
        \RunnerDeck\Alert\LongJobAlert::check([]);

        $this->assertCount(1, $bodies);
        $decoded = json_decode((string) $bodies[0], true);
        $this->assertSame('long_job', $decoded['event']);
        $this->assertStringContainsString('lint', $decoded['message']);
    }
}
