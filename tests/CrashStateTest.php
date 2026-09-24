<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CrashStateTest extends TestCase
{
    private string $stateFile;
    private string $historyFile;

    protected function setUp(): void
    {
        $settingsFile = sys_get_temp_dir() . '/runnerdeck-crash-test-' . uniqid() . '/settings.json';
        putenv('RUNNERDECK_SETTINGS_FILE=' . $settingsFile);
        $this->stateFile = dirname($settingsFile) . '/crash_state.json';

        $this->historyFile = sys_get_temp_dir() . '/runnerdeck-crash-test-' . uniqid() . '/history.sqlite';
        putenv('RUNNERDECK_HISTORY_FILE=' . $this->historyFile);
    }

    protected function tearDown(): void
    {
        $dir = dirname((string) getenv('RUNNERDECK_SETTINGS_FILE'));
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_AUTO_RESTART');
        putenv('RUNNERDECK_HISTORY_FILE');
        @unlink($this->stateFile);
        @unlink($dir . '/settings.json');
        @rmdir($dir);
        @unlink($this->historyFile);
        @rmdir(dirname($this->historyFile));
    }

    /** @return array<int, array<string, mixed>> */
    private function runner(bool $localRunning, bool $configured = true): array
    {
        return [['id' => 'runner-base', 'local_running' => $localRunning, 'configured' => $configured]];
    }

    #[RunInSeparateProcess]
    public function testHealthyRunnerHasNoFlags(): void
    {
        $result = \RunnerDeck\CrashState::track($this->runner(true));

        $this->assertFalse($result[0]['crash_flagged']);
        $this->assertFalse($result[0]['just_flagged']);
        $this->assertFalse($result[0]['should_auto_restart']);
        $this->assertFalse($result[0]['mismatch_flagged']);
    }

    #[RunInSeparateProcess]
    public function testMismatchFlagsAfterThreePolls(): void
    {
        $row = [
            'id' => 'runner-base',
            'local_running' => false,
            'configured' => true,
            'github' => ['status' => 'online', 'busy' => false, 'labels' => []],
        ];

        $first = \RunnerDeck\CrashState::track([$row]);
        $second = \RunnerDeck\CrashState::track([$row]);
        $third = \RunnerDeck\CrashState::track([$row]);

        $this->assertFalse($first[0]['mismatch_flagged']);
        $this->assertFalse($second[0]['mismatch_flagged']);
        $this->assertTrue($third[0]['mismatch_flagged']);
    }

    #[RunInSeparateProcess]
    public function testMismatchResetsWhenStatesAgree(): void
    {
        $offline = [
            'id' => 'runner-base',
            'local_running' => false,
            'configured' => true,
            'github' => ['status' => 'online', 'busy' => false, 'labels' => []],
        ];
        $agreed = [
            'id' => 'runner-base',
            'local_running' => false,
            'configured' => true,
            'github' => ['status' => 'offline', 'busy' => false, 'labels' => []],
        ];

        \RunnerDeck\CrashState::track([$offline]);
        \RunnerDeck\CrashState::track([$offline]);
        $cleared = \RunnerDeck\CrashState::track([$agreed]);

        $this->assertFalse($cleared[0]['mismatch_flagged']);
    }

    #[RunInSeparateProcess]
    public function testNoGithubRecordIsNotAMismatch(): void
    {
        $row = ['id' => 'runner-base', 'local_running' => true, 'configured' => true, 'github' => null];
        $result = \RunnerDeck\CrashState::track([$row]);
        $this->assertFalse($result[0]['mismatch_flagged']);
    }

    #[RunInSeparateProcess]
    public function testCrashFlagsImmediatelyWithoutAutoRestart(): void
    {
        \RunnerDeck\CrashState::track($this->runner(true));
        $result = \RunnerDeck\CrashState::track($this->runner(false));

        $this->assertTrue($result[0]['crash_flagged']);
        $this->assertTrue($result[0]['just_flagged']);
        $this->assertFalse($result[0]['should_auto_restart']);
    }

    #[RunInSeparateProcess]
    public function testJustFlaggedOnlyFiresOnce(): void
    {
        \RunnerDeck\CrashState::track($this->runner(true));
        \RunnerDeck\CrashState::track($this->runner(false));
        $result = \RunnerDeck\CrashState::track($this->runner(false));

        $this->assertTrue($result[0]['crash_flagged']);
        $this->assertFalse($result[0]['just_flagged']);
    }

    #[RunInSeparateProcess]
    public function testAutoRestartAttemptsBeforeFlagging(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');

        for ($i = 0; $i < 3; $i++) {
            \RunnerDeck\CrashState::track($this->runner(true));
            $result = \RunnerDeck\CrashState::track($this->runner(false));
            $this->assertTrue($result[0]['should_auto_restart'], "attempt {$i} should trigger a restart");
            $this->assertFalse($result[0]['crash_flagged'], "attempt {$i} should not be flagged yet");
        }

        \RunnerDeck\CrashState::track($this->runner(true));
        $result = \RunnerDeck\CrashState::track($this->runner(false));
        $this->assertFalse($result[0]['should_auto_restart']);
        $this->assertTrue($result[0]['crash_flagged']);
        $this->assertTrue($result[0]['just_flagged']);
    }

    #[RunInSeparateProcess]
    public function testUnconfiguredSlotNeverFlags(): void
    {
        \RunnerDeck\CrashState::track($this->runner(true, configured: false));
        $result = \RunnerDeck\CrashState::track($this->runner(false, configured: false));

        $this->assertFalse($result[0]['crash_flagged']);
    }

    #[RunInSeparateProcess]
    public function testMarkExplicitlyStoppedSuppressesOneCrash(): void
    {
        \RunnerDeck\CrashState::track($this->runner(true));
        \RunnerDeck\CrashState::markExplicitlyStopped('runner-base');
        $result = \RunnerDeck\CrashState::track($this->runner(false));

        $this->assertFalse($result[0]['crash_flagged']);
    }

    #[RunInSeparateProcess]
    public function testHealthyResetClearsAttemptsAndFlag(): void
    {
        \RunnerDeck\CrashState::track($this->runner(true));
        \RunnerDeck\CrashState::track($this->runner(false));
        $sanity = \RunnerDeck\CrashState::track($this->runner(false));
        $this->assertTrue($sanity[0]['crash_flagged'], 'sanity: still flagged before reset');

        $decoded = json_decode(file_get_contents($this->stateFile) ?: '', true);
        $decoded['runner-base']['healthySince'] = time() - 121;
        file_put_contents($this->stateFile, json_encode($decoded));

        $result = \RunnerDeck\CrashState::track($this->runner(true));

        $this->assertFalse($result[0]['crash_flagged']);
    }

    #[RunInSeparateProcess]
    public function testCrashCount7dStartsAtZero(): void
    {
        $result = \RunnerDeck\CrashState::track($this->runner(true));

        $this->assertSame(0, $result[0]['crash_count_7d']);
    }

    #[RunInSeparateProcess]
    public function testCrashCount7dIncrementsOnEachCrashRegardlessOfAutoRestart(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');

        \RunnerDeck\CrashState::track($this->runner(true));
        $first = \RunnerDeck\CrashState::track($this->runner(false));
        $this->assertSame(1, $first[0]['crash_count_7d']);

        \RunnerDeck\CrashState::track($this->runner(true));
        $second = \RunnerDeck\CrashState::track($this->runner(false));
        $this->assertSame(2, $second[0]['crash_count_7d']);
    }
}
