<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class QueuedJobsTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_REPO');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_LABEL');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_SETTINGS_FILE');
        if ($this->tmpDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    public function testHintStartWhenStoppedSlotsExist(): void
    {
        $hint = \RunnerDeck\QueuedJobs::hintFor(3, 2, 4);
        $this->assertSame('start', $hint['hint_action']);
        $this->assertStringContainsString('2 stopped', $hint['hint']);
    }

    public function testHintAddWhenBusyAndUnderCap(): void
    {
        $hint = \RunnerDeck\QueuedJobs::hintFor(1, 0, 4);
        $this->assertSame('add', $hint['hint_action']);
    }

    public function testHintWaitAtMaxPool(): void
    {
        $hint = \RunnerDeck\QueuedJobs::hintFor(1, 0, 30);
        $this->assertSame('', $hint['hint_action']);
        $this->assertStringContainsString('max size', $hint['hint']);
    }

    public function testHintWaitAtPoolCap(): void
    {
        $hint = \RunnerDeck\QueuedJobs::hintFor(2, 0, 4, 3, 3);
        $this->assertSame('', $hint['hint_action']);
        $this->assertStringContainsString('Pool cap', $hint['hint']);
    }

    public function testPickQueuedJobsFiltersByStatusAndLabel(): void
    {
        $jobs = \RunnerDeck\QueuedJobs::pickQueuedJobs([
            'jobs' => [
                [
                    'id' => 1,
                    'name' => 'build',
                    'status' => 'queued',
                    'labels' => ['self-hosted-runnerdeck', 'linux'],
                    'html_url' => 'https://github.com/acme/app/actions/runs/1/job/1',
                    'workflow_name' => 'CI',
                    'created_at' => date('c', time() - 90),
                ],
                [
                    'id' => 2,
                    'name' => 'other-label',
                    'status' => 'queued',
                    'labels' => ['ubuntu-latest'],
                    'html_url' => '',
                    'workflow_name' => 'CI',
                    'created_at' => date('c'),
                ],
                [
                    'id' => 3,
                    'name' => 'already-running',
                    'status' => 'in_progress',
                    'labels' => ['self-hosted-runnerdeck'],
                    'html_url' => '',
                    'workflow_name' => 'CI',
                    'created_at' => date('c'),
                ],
            ],
        ], 'acme/app', 'self-hosted-runnerdeck');

        $this->assertCount(1, $jobs);
        $this->assertSame(1, $jobs[0]['id']);
        $this->assertSame('build', $jobs[0]['name']);
        $this->assertSame('acme/app', $jobs[0]['repo']);
        $this->assertSame('1m', $jobs[0]['waited']);
    }

    public function testPickRecentJobsUsesCompletedRunnerName(): void
    {
        $recent = \RunnerDeck\QueuedJobs::pickRecentJobs([
            'jobs' => [
                [
                    'id' => 4,
                    'name' => 'test',
                    'status' => 'completed',
                    'conclusion' => 'success',
                    'runner_name' => 'acme-1',
                    'html_url' => 'https://github.com/acme/app/job/4',
                    'workflow_name' => 'CI',
                    'started_at' => date('c', time() - 90),
                    'completed_at' => date('c', time() - 30),
                ],
                [
                    'id' => 5,
                    'name' => 'still-running',
                    'status' => 'in_progress',
                    'runner_name' => 'acme-1',
                ],
            ],
        ], 'acme/app');

        $this->assertCount(1, $recent);
        $this->assertSame('test', $recent[0]['name']);
        $this->assertSame('success', $recent[0]['conclusion']);
        $this->assertSame('acme-1', $recent[0]['runner_name']);
        $this->assertSame('1m', $recent[0]['duration']);
    }

    public function testPickLiveJobsUsesInProgressRunnerName(): void
    {
        $live = \RunnerDeck\QueuedJobs::pickLiveJobs([
            'jobs' => [
                [
                    'id' => 8,
                    'name' => 'lint',
                    'status' => 'in_progress',
                    'runner_name' => 'acme-1',
                    'html_url' => 'https://github.com/acme/app/job/8',
                    'workflow_name' => 'CI',
                ],
                [
                    'id' => 9,
                    'name' => 'queued-only',
                    'status' => 'queued',
                    'runner_name' => 'acme-1',
                ],
                [
                    'id' => 10,
                    'name' => 'unassigned',
                    'status' => 'in_progress',
                    'runner_name' => '',
                ],
            ],
        ], 'acme/app');

        $this->assertCount(1, $live);
        $this->assertSame('acme-1', $live[0]['runner_name']);
        $this->assertSame('lint', $live[0]['name']);
        $this->assertSame('acme/app', $live[0]['repo']);
    }

    public function testFormatWait(): void
    {
        $this->assertSame('12s', \RunnerDeck\QueuedJobs::formatWait(12));
        $this->assertSame('3m', \RunnerDeck\QueuedJobs::formatWait(180));
        $this->assertSame('2h', \RunnerDeck\QueuedJobs::formatWait(7200));
    }

    #[RunInSeparateProcess]
    public function testSnapshotSkipsGhApiWhenNotLoggedIn(): void
    {
        $this->isolate();
        $calls = 0;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$calls): array {
            $calls++;
            if (in_array('auth', $cmd, true)) {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in'];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'unexpected ' . implode(' ', $cmd)];
        });

        $snap = \RunnerDeck\QueuedJobs::snapshot();

        $this->assertTrue($snap['ok']);
        $this->assertSame(0, $snap['count']);
        $this->assertSame('gh CLI is not authenticated', $snap['message']);
        $this->assertSame(1, $calls);
    }

    #[RunInSeparateProcess]
    public function testSnapshotRepoScopeCollectsMatchingJobsAndCaches(): void
    {
        $this->isolate();
        putenv('RUNNERDECK_SCOPE=repo');
        putenv('RUNNERDECK_REPO=acme/app');
        $apiCalls = 0;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$apiCalls): array {
            if (in_array('auth', $cmd, true)) {
                return ['code' => 0, 'stdout' => 'ok', 'stderr' => ''];
            }
            $apiCalls++;
            $path = (string) end($cmd);
            if (str_contains($path, 'status=queued')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode(['workflow_runs' => [['id' => 77]]]),
                    'stderr' => '',
                ];
            }
            if (str_contains($path, 'status=in_progress') || str_contains($path, 'status=completed')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode(['workflow_runs' => []]),
                    'stderr' => '',
                ];
            }
            if (str_contains($path, '/jobs')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode([
                        'jobs' => [[
                            'id' => 9,
                            'name' => 'test',
                            'status' => 'queued',
                            'labels' => ['self-hosted-runnerdeck'],
                            'html_url' => 'https://github.com/acme/app/actions/runs/77/job/9',
                            'workflow_name' => 'CI',
                            'created_at' => date('c'),
                        ]],
                    ]),
                    'stderr' => '',
                ];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => $path];
        });

        $first = \RunnerDeck\QueuedJobs::snapshot();
        $second = \RunnerDeck\QueuedJobs::snapshot();

        $this->assertTrue($first['ok']);
        $this->assertSame(1, $first['count']);
        $this->assertSame('test', $first['jobs'][0]['name']);
        $this->assertSame('add', $first['hint_action']);
        $this->assertSame([], $first['live']);
        $this->assertSame([], $first['recent']);
        $this->assertSame($first['count'], $second['count']);
        $this->assertSame(4, $apiCalls);
    }

    #[RunInSeparateProcess]
    public function testSnapshotOrgSkipsArchivedAndForbiddenRepos(): void
    {
        $this->isolate();
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_ORG=acme');
        \RunnerDeck\Shell::fake(function (array $cmd): array {
            if (in_array('auth', $cmd, true)) {
                return ['code' => 0, 'stdout' => 'ok', 'stderr' => ''];
            }
            $path = (string) end($cmd);
            if (str_contains($path, 'orgs/acme/repos')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode([
                        ['full_name' => 'acme/old', 'archived' => true],
                        ['full_name' => 'acme/app'],
                    ]),
                    'stderr' => '',
                ];
            }
            if (str_contains($path, 'repos/acme/app/actions/runs?status=queued')) {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'HTTP 403'];
            }
            if (str_contains($path, 'repos/acme/app/actions/runs?status=in_progress')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode(['workflow_runs' => [['id' => 3]]]),
                    'stderr' => '',
                ];
            }
            if (str_contains($path, '/jobs')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode([
                        'jobs' => [[
                            'id' => 4,
                            'name' => 'wait',
                            'status' => 'waiting',
                            'labels' => ['self-hosted-runnerdeck'],
                            'html_url' => 'https://github.com/acme/app/job/4',
                            'workflow_name' => 'Deploy',
                            'created_at' => date('c'),
                        ]],
                    ]),
                    'stderr' => '',
                ];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => $path];
        });

        $snap = \RunnerDeck\QueuedJobs::snapshot();

        $this->assertTrue($snap['ok']);
        $this->assertSame(1, $snap['count']);
        $this->assertSame('wait', $snap['jobs'][0]['name']);
    }

    private function isolate(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/runnerdeck-queue-test-' . uniqid();
        mkdir($this->tmpDir . '/pool', 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->tmpDir . '/settings.json');
        putenv('RUNNERDECK_POOL_DIR=' . $this->tmpDir . '/pool');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
    }
}
