<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-dash-test-' . uniqid();
        mkdir($this->dir, 0755, true);
        putenv('RUNNERDECK_POOL_DIR=' . dirname($this->dir));
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');

        \RunnerDeck\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\RunnerPool::fakeCheckProcess(fn () => [false, null]);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        \RunnerDeck\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\RunnerPool::fakeCheckProcess(null);
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testApplyAutoRestartsStartsFlaggedRunners(): void
    {
        $spawned = false;
        \RunnerDeck\Shell::fake(function () use (&$spawned): array {
            $spawned = true;
            return ['code' => 0, 'stdout' => "4242\n", 'stderr' => ''];
        });

        $info = new \RunnerDeck\RunnerInfo(
            id: 'runner-1',
            dir: $this->dir,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );

        \RunnerDeck\Dashboard::applyAutoRestarts(
            ['runner-1' => $info],
            [['id' => 'runner-1', 'should_auto_restart' => true]],
        );

        $this->assertTrue($spawned);
        $this->assertSame('4242', trim((string) file_get_contents($this->dir . '/runner.pid')));
    }

    #[RunInSeparateProcess]
    public function testApplyAutoRestartsSkipsRunnersWithoutFlag(): void
    {
        $spawned = false;
        \RunnerDeck\Shell::fake(function () use (&$spawned): array {
            $spawned = true;
            return ['code' => 0, 'stdout' => "1\n", 'stderr' => ''];
        });

        $info = new \RunnerDeck\RunnerInfo(
            id: 'runner-1',
            dir: $this->dir,
            configured: true,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );

        \RunnerDeck\Dashboard::applyAutoRestarts(
            ['runner-1' => $info],
            [['id' => 'runner-1', 'should_auto_restart' => false]],
        );

        $this->assertFalse($spawned);
    }
}
