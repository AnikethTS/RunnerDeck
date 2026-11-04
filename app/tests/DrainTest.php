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
        \RunnerDeck\Core\Shell::fake(null);
        \RunnerDeck\Pool\Drain::fakeSleep(null);
        \RunnerDeck\Pool\Drain::fakeNow(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    #[RunInSeparateProcess]
    public function testWaitUntilIdleReturnsWhenGithubIsIdle(): void
    {
        $calls = 0;
        \RunnerDeck\Core\Shell::fake(function () use (&$calls): array {
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
        \RunnerDeck\Pool\Drain::fakeSleep(static function (): void {
        });

        $result = \RunnerDeck\Pool\Drain::waitUntilIdle('acme-1', 10);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $calls);
    }

    #[RunInSeparateProcess]
    public function testWaitUntilIdleTimesOutWhileBusy(): void
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

        $result = \RunnerDeck\Pool\Drain::waitUntilIdle('acme-1', 3, 2);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['timed_out'] ?? false);
        $this->assertTrue($result['busy'] ?? false);
    }

    #[RunInSeparateProcess]
    public function testAuditWritesInfoWithoutSecrets(): void
    {
        $dir = sys_get_temp_dir() . '/runnerdeck-drain-audit-' . uniqid();
        mkdir($dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $dir . '/settings.json');

        \RunnerDeck\Pool\Drain::audit(['runner-1'], false);

        $raw = (string) file_get_contents($dir . '/runnerdeck.log');
        $this->assertStringContainsString('process.drain', $raw);
        $this->assertStringContainsString('drain-stop runner-1', $raw);
        $this->assertStringContainsString('"level":"info"', $raw);

        exec('rm -rf ' . escapeshellarg($dir));
        putenv('RUNNERDECK_SETTINGS_FILE');
    }
}
