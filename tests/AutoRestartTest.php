<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AutoRestartTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-ar-' . uniqid() . '/runner-1';
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
        exec('rm -rf ' . escapeshellarg(dirname($this->dir)));
    }

    #[RunInSeparateProcess]
    public function testTickIsNoopWhenAutoRestartIsOff(): void
    {
        $this->assertSame([], \RunnerDeck\AutoRestart::tick());
    }

    #[RunInSeparateProcess]
    public function testStartFlaggedStartsOnlyMarkedRunners(): void
    {
        $spawned = [];
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$spawned): array {
            $spawned[] = $cmd;
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
        $started = \RunnerDeck\AutoRestart::startFlagged([
            array_merge($info->toArray(), ['should_auto_restart' => true]),
        ]);

        $this->assertCount(1, $started);
        $this->assertTrue($started[0]['ok']);
        $this->assertNotSame([], $spawned);
        $this->assertFileExists($this->dir . '/runner.pid');
    }
}
