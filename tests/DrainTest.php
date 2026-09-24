<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DrainTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        \RunnerDeck\Drain::fakeSleep(null);
        \RunnerDeck\Drain::fakeNow(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
    }

    #[RunInSeparateProcess]
    public function testWaitUntilIdleReturnsWhenGithubIsIdle(): void
    {
        $calls = 0;
        \RunnerDeck\Shell::fake(function () use (&$calls): array {
            $calls++;
            return [
                'code' => 0,
                'stdout' => json_encode([
                    'runners' => [[
                        'id' => 1,
                        'name' => 'acme-1',
                        'status' => 'online',
                        'busy' => $calls === 1,
                        'labels' => [],
                    ]],
                ]),
                'stderr' => '',
            ];
        });
        \RunnerDeck\Drain::fakeSleep(static function (): void {
        });

        $result = \RunnerDeck\Drain::waitUntilIdle('acme-1', 10);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $calls);
    }

    #[RunInSeparateProcess]
    public function testWaitUntilIdleTimesOutWhileBusy(): void
    {
        \RunnerDeck\Shell::fake(fn () => [
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
        \RunnerDeck\Drain::fakeNow(static function () use (&$now): int {
            return $now;
        });
        \RunnerDeck\Drain::fakeSleep(static function (int $seconds) use (&$now): void {
            $now += $seconds;
        });

        $result = \RunnerDeck\Drain::waitUntilIdle('acme-1', 3, 2);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['timed_out'] ?? false);
        $this->assertTrue($result['busy'] ?? false);
    }
}
