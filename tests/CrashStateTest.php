<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CrashStateTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $settingsFile = sys_get_temp_dir() . '/runnerdeck-crash-test-' . uniqid() . '/settings.json';
        putenv('RUNNERDECK_SETTINGS_FILE=' . $settingsFile);
        $this->stateFile = dirname($settingsFile) . '/crash_state.json';
    }

    protected function tearDown(): void
    {
        $dir = dirname((string) getenv('RUNNERDECK_SETTINGS_FILE'));
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_AUTO_RESTART');
        @unlink($this->stateFile);
        @unlink($dir . '/settings.json');
        @rmdir($dir);
    }

    /** @return array<int, array<string, mixed>> */
    private function runner(bool $localRunning, bool $configured = true): array
    {
        return [['id' => 'runner-base', 'local_running' => $localRunning, 'configured' => $configured]];
    }

    #[RunInSeparateProcess]
    public function testHealthyRunnerHasNoFlags(): void
    {
        $result = \CrashState::track($this->runner(true));

        $this->assertFalse($result[0]['crash_flagged']);
        $this->assertFalse($result[0]['just_flagged']);
        $this->assertFalse($result[0]['should_auto_restart']);
    }

    #[RunInSeparateProcess]
    public function testCrashFlagsImmediatelyWithoutAutoRestart(): void
    {
        \CrashState::track($this->runner(true));
        $result = \CrashState::track($this->runner(false));

        $this->assertTrue($result[0]['crash_flagged']);
        $this->assertTrue($result[0]['just_flagged']);
        $this->assertFalse($result[0]['should_auto_restart']);
    }

    #[RunInSeparateProcess]
    public function testJustFlaggedOnlyFiresOnce(): void
    {
        \CrashState::track($this->runner(true));
        \CrashState::track($this->runner(false));
        $result = \CrashState::track($this->runner(false));

        $this->assertTrue($result[0]['crash_flagged']);
        $this->assertFalse($result[0]['just_flagged']);
    }

    #[RunInSeparateProcess]
    public function testAutoRestartAttemptsBeforeFlagging(): void
    {
        putenv('RUNNERDECK_AUTO_RESTART=1');

        for ($i = 0; $i < 3; $i++) {
            \CrashState::track($this->runner(true));
            $result = \CrashState::track($this->runner(false));
            $this->assertTrue($result[0]['should_auto_restart'], "attempt {$i} should trigger a restart");
            $this->assertFalse($result[0]['crash_flagged'], "attempt {$i} should not be flagged yet");
        }

        \CrashState::track($this->runner(true));
        $result = \CrashState::track($this->runner(false));
        $this->assertFalse($result[0]['should_auto_restart']);
        $this->assertTrue($result[0]['crash_flagged']);
        $this->assertTrue($result[0]['just_flagged']);
    }

    #[RunInSeparateProcess]
    public function testUnconfiguredSlotNeverFlags(): void
    {
        \CrashState::track($this->runner(true, configured: false));
        $result = \CrashState::track($this->runner(false, configured: false));

        $this->assertFalse($result[0]['crash_flagged']);
    }

    #[RunInSeparateProcess]
    public function testMarkExplicitlyStoppedSuppressesOneCrash(): void
    {
        \CrashState::track($this->runner(true));
        \CrashState::markExplicitlyStopped('runner-base');
        $result = \CrashState::track($this->runner(false));

        $this->assertFalse($result[0]['crash_flagged']);
    }

    #[RunInSeparateProcess]
    public function testHealthyResetClearsAttemptsAndFlag(): void
    {
        \CrashState::track($this->runner(true));
        \CrashState::track($this->runner(false));
        $sanity = \CrashState::track($this->runner(false));
        $this->assertTrue($sanity[0]['crash_flagged'], 'sanity: still flagged before reset');

        $decoded = json_decode(file_get_contents($this->stateFile) ?: '', true);
        $decoded['runner-base']['healthySince'] = time() - 121;
        file_put_contents($this->stateFile, json_encode($decoded));

        $result = \CrashState::track($this->runner(true));

        $this->assertFalse($result[0]['crash_flagged']);
    }
}
