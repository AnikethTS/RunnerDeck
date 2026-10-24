<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class FailedJobsTest extends TestCase
{
    public function testLatestFailedJobWinsOverOlderSuccess(): void
    {
        $latest = \RunnerDeck\GitHub\FailedJobs::latestByRunner([
            [
                'id' => 1,
                'name' => 'old',
                'runner_name' => 'acme-1',
                'conclusion' => 'success',
                'completed_at' => 100,
            ],
            [
                'id' => 2,
                'name' => 'lint',
                'runner_name' => 'acme-1',
                'conclusion' => 'failure',
                'completed_at' => 200,
            ],
        ]);
        $this->assertSame('lint', $latest['acme-1']['name']);
        $this->assertSame('failure', $latest['acme-1']['conclusion']);
    }

    #[RunInSeparateProcess]
    public function testAnnotateReadsQueueCacheWithoutCallingGithub(): void
    {
        $dir = sys_get_temp_dir() . '/runnerdeck-failed-' . uniqid();
        mkdir($dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $dir . '/settings.json');
        file_put_contents($dir . '/queue_cache.json', json_encode([
            'fetched_at' => 1,
            'payload' => [
                'recent' => [
                    [
                        'id' => 8,
                        'name' => 'lint',
                        'runner_name' => 'acme-1',
                        'conclusion' => 'failure',
                        'completed_at' => 200,
                    ],
                    [
                        'id' => 9,
                        'name' => 'ci',
                        'runner_name' => 'acme-2',
                        'conclusion' => 'success',
                        'completed_at' => 201,
                    ],
                ],
            ],
        ]));
        $spawned = false;
        \RunnerDeck\Core\Shell::fake(function () use (&$spawned): array {
            $spawned = true;
            return ['code' => 1, 'stdout' => '', 'stderr' => ''];
        });

        $rows = \RunnerDeck\GitHub\FailedJobs::annotate([
            ['id' => 'runner-1', 'agent_name' => 'acme-1'],
            ['id' => 'runner-2', 'agent_name' => 'acme-2'],
        ]);

        $this->assertTrue($rows[0]['last_job_failed']);
        $this->assertSame('lint', $rows[0]['last_job_name']);
        $this->assertFalse($rows[1]['last_job_failed']);
        $this->assertFalse($spawned);
        \RunnerDeck\Core\Shell::fake(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
