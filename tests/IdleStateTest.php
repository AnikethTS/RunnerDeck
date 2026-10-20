<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class IdleStateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-idle-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        \RunnerDeck\IdleState::fakeNow(fn () => 1000);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\IdleState::fakeNow(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testDueAfterIdleMinutes(): void
    {
        \RunnerDeck\IdleState::sync([
            [
                'id' => 'runner-1',
                'local_running' => true,
                'github' => ['busy' => false],
            ],
        ]);
        $this->assertSame([], \RunnerDeck\IdleState::dueIds(1));

        \RunnerDeck\IdleState::fakeNow(fn () => 1000 + 60);
        $this->assertSame(['runner-1'], \RunnerDeck\IdleState::dueIds(1));
    }

    #[RunInSeparateProcess]
    public function testBusyClearsIdleClock(): void
    {
        \RunnerDeck\IdleState::sync([
            [
                'id' => 'runner-1',
                'local_running' => true,
                'github' => ['busy' => false],
            ],
        ]);
        \RunnerDeck\IdleState::sync([
            [
                'id' => 'runner-1',
                'local_running' => true,
                'github' => ['busy' => true],
            ],
        ]);
        \RunnerDeck\IdleState::fakeNow(fn () => 1000 + 600);
        $this->assertSame([], \RunnerDeck\IdleState::dueIds(1));
    }
}
