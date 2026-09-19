<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private string $poolDir;
    private string $runnerDir;

    protected function setUp(): void
    {
        $this->poolDir = sys_get_temp_dir() . '/runnerdeck-dash-test-' . uniqid();
        $this->runnerDir = $this->poolDir . '/runner-1';
        mkdir($this->runnerDir, 0755, true);
        file_put_contents($this->runnerDir . '/.runner', json_encode(['agentName' => 'acme-1']));

        putenv('RUNNERDECK_POOL_DIR=' . $this->poolDir);
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        \RunnerDeck\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\RunnerPool::fakeCheckProcess(null);
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_AUTO_RESTART');
        exec('rm -rf ' . escapeshellarg($this->poolDir));
    }

    #[RunInSeparateProcess]
    public function testSnapshotNeverSpawnsAProcessEvenWhenAutoRestartShouldFire(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');

        $spawned = false;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$spawned): array {
            if (($cmd[0] ?? '') === 'bash') {
                // This is the runner-spawn command (ProcessControl::startIndividual).
                // action=status is a GET with no CSRF check — it must never reach here.
                $spawned = true;
                return ['code' => 0, 'stdout' => "4242\n", 'stderr' => ''];
            }
            // Anything else is a `gh` call (GithubClient) — report "not logged in"
            // so snapshot() short-circuits past it without needing more fakes.
            return ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in'];
        });

        // First poll: the runner is running, to prime CrashState's wasRunning=true.
        \RunnerDeck\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [true, 999]);
        \RunnerDeck\Dashboard::snapshot();

        // Second poll: it crashed. With auto-restart on, this is exactly the
        // scenario that used to call ProcessControl::startIndividual() directly
        // from inside this GET action — the regression this test guards against.
        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [false, null]);
        $result = \RunnerDeck\Dashboard::snapshot();

        $this->assertTrue(
            $result['runners'][0]['should_auto_restart'],
            'sanity: this poll should have flagged the crash, or the test proves nothing'
        );
        $this->assertFalse(
            $spawned,
            'action=status (GET) must never spawn a process — it has no CSRF check by design'
        );
    }
}
