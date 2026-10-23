<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class WatchHeartbeatTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-watch-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_AUTO_RESTART');
        putenv('RUNNERDECK_SCALE_TO_QUEUE');
        putenv('RUNNERDECK_IDLE_STOP_MINUTES');
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES');
        putenv('RUNNERDECK_LONG_JOB_MINUTES');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        \RunnerDeck\WatchHeartbeat::fakeNow(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\WatchHeartbeat::fakeNow(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_AUTO_RESTART');
        putenv('RUNNERDECK_SCALE_TO_QUEUE');
        putenv('RUNNERDECK_IDLE_STOP_MINUTES');
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES');
        putenv('RUNNERDECK_LONG_JOB_MINUTES');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testWarningSilentWhenWatchFeaturesOff(): void
    {
        $this->assertNull(\RunnerDeck\WatchHeartbeat::warning(false));
    }

    #[RunInSeparateProcess]
    public function testWarningWhenAutoRestartOnAndNoTick(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        $text = \RunnerDeck\WatchHeartbeat::warning(false);
        $this->assertNotNull($text);
        $this->assertStringContainsString('watch-auto-restart.php', $text);
    }

    #[RunInSeparateProcess]
    public function testTouchClearsStaleWarning(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        \RunnerDeck\WatchHeartbeat::fakeNow(fn () => 1_700_000_000);
        \RunnerDeck\WatchHeartbeat::touch();
        $this->assertNull(\RunnerDeck\WatchHeartbeat::warning(false));
    }

    #[RunInSeparateProcess]
    public function testStaleAfterTwentySeconds(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        $t = 1_700_000_000;
        \RunnerDeck\WatchHeartbeat::fakeNow(fn () => $t);
        \RunnerDeck\WatchHeartbeat::touch();
        \RunnerDeck\WatchHeartbeat::fakeNow(fn () => $t + 21);
        $this->assertNotNull(\RunnerDeck\WatchHeartbeat::warning(false));
    }

    #[RunInSeparateProcess]
    public function testEphemeralNeedsHeartbeatEvenWhenFlagsOff(): void
    {
        $this->assertNotNull(\RunnerDeck\WatchHeartbeat::warning(true));
    }
}
