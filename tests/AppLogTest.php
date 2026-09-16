<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AppLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-applog-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        \RunnerDeck\AppLog::fakeMaxBytes(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\AppLog::fakeMaxBytes(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @return array<string, mixed> */
    private function lastRecord(): array
    {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents(\RunnerDeck\AppLog::path()))));
        $decoded = json_decode($lines[count($lines) - 1], true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    #[RunInSeparateProcess]
    public function testErrorWritesJsonLineWithActionAndMessage(): void
    {
        \RunnerDeck\AppLog::error('process.start', 'failed to start runner-1', [
            'runner' => 'runner-1',
            'stderr' => 'nohup: failed',
        ]);

        $record = $this->lastRecord();
        $this->assertSame('process.start', $record['action']);
        $this->assertSame('failed to start runner-1', $record['message']);
        $this->assertSame('runner-1', $record['runner']);
        $this->assertSame('nohup: failed', $record['stderr']);
        $this->assertArrayHasKey('time', $record);
        $this->assertFileExists(\RunnerDeck\AppLog::path());
    }

    #[RunInSeparateProcess]
    public function testRedactStripsGithubTokensAndFlagValues(): void
    {
        $this->assertSame(
            'got [redacted] from gh',
            \RunnerDeck\AppLog::redact('got ghs_abcdefghijklmnopqrstuvwxyz from gh')
        );
        $this->assertSame(
            './config.sh --token [redacted] --unattended',
            \RunnerDeck\AppLog::redact('./config.sh --token ghs_abcdefghijklmnopqrstuvwxyz --unattended')
        );
        $this->assertSame(
            'pat [redacted]',
            \RunnerDeck\AppLog::redact('pat github_pat_11AAAAAAA_abcdefghijklmnopqrstuvwxyz')
        );
    }

    #[RunInSeparateProcess]
    public function testRedactStripsConfiguredTotpSecret(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        $this->assertSame('secret=[redacted]', \RunnerDeck\AppLog::redact('secret=JBSWY3DPEHPK3PXP'));
    }

    #[RunInSeparateProcess]
    public function testErrorNeverWritesSecretsToTheFile(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        $msg = 'config.sh failed: --token ghs_abcdefghijklmnopqrstuvwxyz JBSWY3DPEHPK3PXP';
        \RunnerDeck\AppLog::error('provision.config', $msg, [
            'stderr' => 'token=ghs_abcdefghijklmnopqrstuvwxyz secret=JBSWY3DPEHPK3PXP',
        ]);

        $raw = (string) file_get_contents(\RunnerDeck\AppLog::path());
        $this->assertStringNotContainsString('ghs_abcdefghijklmnopqrstuvwxyz', $raw);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $raw);
        $this->assertStringContainsString('[redacted]', $raw);
    }

    #[RunInSeparateProcess]
    public function testRotateMovesCurrentFileWhenOverCap(): void
    {
        \RunnerDeck\AppLog::fakeMaxBytes(80);
        \RunnerDeck\AppLog::error('process.start', 'first-failure-xxxxxxxxxxxxxxxxxxxx');
        $this->assertFileDoesNotExist($this->dir . '/runnerdeck.log.1');

        \RunnerDeck\AppLog::error('process.start', 'second-failure-xxxxxxxxxxxxxxxxxxxx');

        $this->assertFileExists($this->dir . '/runnerdeck.log.1');
        $rotated = (string) file_get_contents($this->dir . '/runnerdeck.log.1');
        $this->assertStringContainsString('first-failure', $rotated);
        $this->assertStringContainsString('second-failure', (string) file_get_contents(\RunnerDeck\AppLog::path()));
        $this->assertStringNotContainsString('first-failure', (string) file_get_contents(\RunnerDeck\AppLog::path()));
    }

    #[RunInSeparateProcess]
    public function testThrottledWritesOncePerAction(): void
    {
        \RunnerDeck\AppLog::errorThrottled('gh.list_runners', 'gh down');
        \RunnerDeck\AppLog::errorThrottled('gh.list_runners', 'gh down again');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents(\RunnerDeck\AppLog::path()))));
        $this->assertCount(1, $lines);
    }
}
