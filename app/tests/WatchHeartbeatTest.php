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
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT');
        putenv('RUNNERDECK_LOG_RETENTION_DAYS');
        \RunnerDeck\Host\WatchHeartbeat::fakeNow(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Host\WatchHeartbeat::fakeNow(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_AUTO_RESTART');
        putenv('RUNNERDECK_SCALE_TO_QUEUE');
        putenv('RUNNERDECK_IDLE_STOP_MINUTES');
        putenv('RUNNERDECK_QUEUE_WAIT_MINUTES');
        putenv('RUNNERDECK_LONG_JOB_MINUTES');
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT');
        putenv('RUNNERDECK_LOG_RETENTION_DAYS');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testWarningSilentWhenWatchFeaturesOff(): void
    {
        $this->assertNull(\RunnerDeck\Host\WatchHeartbeat::warning(false));
    }

    #[RunInSeparateProcess]
    public function testWarningWhenAutoRestartOnAndNoTick(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        $text = \RunnerDeck\Host\WatchHeartbeat::warning(false);
        $this->assertNotNull($text);
        $this->assertStringContainsString('watch-auto-restart.php', $text);
    }

    #[RunInSeparateProcess]
    public function testTouchClearsStaleWarning(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        \RunnerDeck\Host\WatchHeartbeat::fakeNow(fn () => 1_700_000_000);
        \RunnerDeck\Host\WatchHeartbeat::touch();
        $this->assertNull(\RunnerDeck\Host\WatchHeartbeat::warning(false));
    }

    #[RunInSeparateProcess]
    public function testStaleAfterTwentySeconds(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');
        $t = 1_700_000_000;
        \RunnerDeck\Host\WatchHeartbeat::fakeNow(fn () => $t);
        \RunnerDeck\Host\WatchHeartbeat::touch();
        \RunnerDeck\Host\WatchHeartbeat::fakeNow(fn () => $t + 21);
        $this->assertNotNull(\RunnerDeck\Host\WatchHeartbeat::warning(false));
    }

    #[RunInSeparateProcess]
    public function testEphemeralNeedsHeartbeatEvenWhenFlagsOff(): void
    {
        $this->assertNotNull(\RunnerDeck\Host\WatchHeartbeat::warning(true));
    }

    #[RunInSeparateProcess]
    public function testBundleSnapshotNeedsHeartbeat(): void
    {
        putenv('RUNNERDECK_BUNDLE_SNAPSHOT=1');
        $this->assertNotNull(\RunnerDeck\Host\WatchHeartbeat::warning(false));
    }

    #[RunInSeparateProcess]
    public function testLogRetentionNeedsHeartbeat(): void
    {
        putenv('RUNNERDECK_LOG_RETENTION_DAYS=14');
        $text = \RunnerDeck\Host\WatchHeartbeat::warning(false);
        $this->assertNotNull($text);
        $this->assertStringContainsString('log retention', $text);
    }
}
