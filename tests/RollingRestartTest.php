<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Pool\RunnerInfo;

final class RollingRestartTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Core\Shell::fake(null);
        \RunnerDeck\Pool\Drain::fakeSleep(null);
        \RunnerDeck\Pool\Drain::fakeNow(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
    }

    public function testSkipsUnconfiguredSlots(): void
    {
        $r = new RunnerInfo(
            id: 'runner-1',
            dir: '/tmp/missing-slot',
            configured: false,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );

        $result = \RunnerDeck\Pool\RollingRestart::run([$r], false, 10);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('No configured runners', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testStopsWhenSlotStaysBusy(): void
    {
        \RunnerDeck\Core\Shell::fake(fn () => [
            'code' => 0,
            'stdout' => json_encode([
                'runners' => [[
                    'id' => 1,
                    'name' => 'acme-1',
                    'status' => 'online',
                    'busy' => true,
                    'labels' => [],
                ]],
            ]),
            'stderr' => '',
        ]);
        $now = 0;
        \RunnerDeck\Pool\Drain::fakeNow(static function () use (&$now): int {
            return $now;
        });
        \RunnerDeck\Pool\Drain::fakeSleep(static function (int $seconds) use (&$now): void {
            $now += $seconds;
        });

        $r = new RunnerInfo(
            id: 'runner-1',
            dir: '/tmp/missing-slot',
            configured: true,
            agentName: 'acme-1',
            localRunning: true,
            pid: 9,
            logTail: [],
        );

        $result = \RunnerDeck\Pool\RollingRestart::run([$r], false, 3);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['timed_out'] ?? false);
        $this->assertSame(409, \RunnerDeck\Pool\RollingRestart::status($result));
    }
}
