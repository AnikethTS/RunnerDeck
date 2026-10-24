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
        \RunnerDeck\Core\AppLog::fakeMaxBytes(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Core\AppLog::fakeMaxBytes(null);
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
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents(\RunnerDeck\Core\AppLog::path()))));
        $decoded = json_decode($lines[count($lines) - 1], true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    #[RunInSeparateProcess]
    public function testErrorWritesJsonLineWithActionAndMessage(): void
    {
        \RunnerDeck\Core\AppLog::error('process.start', 'failed to start runner-1', [
            'runner' => 'runner-1',
            'stderr' => 'nohup: failed',
        ]);

        $record = $this->lastRecord();
        $this->assertSame('process.start', $record['action']);
        $this->assertSame('failed to start runner-1', $record['message']);
        $this->assertSame('runner-1', $record['runner']);
        $this->assertSame('nohup: failed', $record['stderr']);
        $this->assertSame('error', $record['level']);
        $this->assertArrayHasKey('time', $record);
        $this->assertFileExists(\RunnerDeck\Core\AppLog::path());
    }

    #[RunInSeparateProcess]
    public function testRedactStripsGithubTokensAndFlagValues(): void
    {
        $this->assertSame(
            'got [redacted] from gh',
            \RunnerDeck\Core\AppLog::redact('got ghs_abcdefghijklmnopqrstuvwxyz from gh')
        );
        $this->assertSame(
            './config.sh --token [redacted] --unattended',
            \RunnerDeck\Core\AppLog::redact('./config.sh --token ghs_abcdefghijklmnopqrstuvwxyz --unattended')
        );
        $this->assertSame(
            'pat [redacted]',
            \RunnerDeck\Core\AppLog::redact('pat github_pat_11AAAAAAA_abcdefghijklmnopqrstuvwxyz')
        );
    }

    #[RunInSeparateProcess]
    public function testRedactStripsConfiguredTotpSecret(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        $this->assertSame('secret=[redacted]', \RunnerDeck\Core\AppLog::redact('secret=JBSWY3DPEHPK3PXP'));
    }

    #[RunInSeparateProcess]
    public function testErrorNeverWritesSecretsToTheFile(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=JBSWY3DPEHPK3PXP');
        $msg = 'config.sh failed: --token ghs_abcdefghijklmnopqrstuvwxyz JBSWY3DPEHPK3PXP';
        \RunnerDeck\Core\AppLog::error('provision.config', $msg, [
            'stderr' => 'token=ghs_abcdefghijklmnopqrstuvwxyz secret=JBSWY3DPEHPK3PXP',
        ]);

        $raw = (string) file_get_contents(\RunnerDeck\Core\AppLog::path());
        $this->assertStringNotContainsString('ghs_abcdefghijklmnopqrstuvwxyz', $raw);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $raw);
        $this->assertStringContainsString('[redacted]', $raw);
    }

    #[RunInSeparateProcess]
    public function testInfoWritesLevelWithoutStderr(): void
    {
        \RunnerDeck\Core\AppLog::info('process.stop', 'runner-1 stopped (pid 1)', ['runner' => 'runner-1']);

        $record = $this->lastRecord();
        $this->assertSame('info', $record['level']);
        $this->assertSame('process.stop', $record['action']);
        $this->assertArrayNotHasKey('stderr', $record);
    }

    #[RunInSeparateProcess]
    public function testRotateMovesCurrentFileWhenOverCap(): void
    {
        \RunnerDeck\Core\AppLog::fakeMaxBytes(80);
        \RunnerDeck\Core\AppLog::error('process.start', 'first-failure-xxxxxxxxxxxxxxxxxxxx');
        $this->assertFileDoesNotExist($this->dir . '/runnerdeck.log.1');

        \RunnerDeck\Core\AppLog::error('process.start', 'second-failure-xxxxxxxxxxxxxxxxxxxx');

        $this->assertFileExists($this->dir . '/runnerdeck.log.1');
        $rotated = (string) file_get_contents($this->dir . '/runnerdeck.log.1');
        $this->assertStringContainsString('first-failure', $rotated);
        $current = (string) file_get_contents(\RunnerDeck\Core\AppLog::path());
        $this->assertStringContainsString('second-failure', $current);
        $this->assertStringNotContainsString('first-failure', $current);
    }

    #[RunInSeparateProcess]
    public function testThrottledWritesOncePerAction(): void
    {
        \RunnerDeck\Core\AppLog::errorThrottled('gh.list_runners', 'gh down');
        \RunnerDeck\Core\AppLog::errorThrottled('gh.list_runners', 'gh down again');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents(\RunnerDeck\Core\AppLog::path()))));
        $this->assertCount(1, $lines);
    }

    #[RunInSeparateProcess]
    public function testRecentReturnsNewestLastAndReadsRotatedFile(): void
    {
        \RunnerDeck\Core\AppLog::fakeMaxBytes(80);
        \RunnerDeck\Core\AppLog::error('process.start', 'first-failure-xxxxxxxxxxxxxxxxxxxx');
        \RunnerDeck\Core\AppLog::error('process.start', 'second-failure-xxxxxxxxxxxxxxxxxxxx');

        $recent = \RunnerDeck\Core\AppLog::recent(10);
        $this->assertCount(2, $recent);
        $this->assertStringContainsString('first-failure', $recent[0]['message']);
        $this->assertStringContainsString('second-failure', $recent[1]['message']);
        $files = \RunnerDeck\Core\AppLog::files();
        $this->assertCount(2, $files);
        $this->assertSame($this->dir . '/runnerdeck.log.1', $files[0]);
        $this->assertSame(\RunnerDeck\Core\AppLog::path(), $files[1]);
    }

    #[RunInSeparateProcess]
    public function testRecentSkipsInvalidLinesAndHonorsLimit(): void
    {
        $okOne = json_encode([
            'time' => '2026-09-22T18:00:00+00:00',
            'action' => 'process.start',
            'message' => 'ok-one',
        ]);
        $okTwo = json_encode([
            'time' => '2026-09-22T18:01:00+00:00',
            'action' => 'gh.list_runners',
            'message' => 'ok-two',
        ]);
        file_put_contents(
            \RunnerDeck\Core\AppLog::path(),
            "not-json\n{$okOne}\n{$okTwo}\n"
        );

        $recent = \RunnerDeck\Core\AppLog::recent(1);
        $this->assertCount(1, $recent);
        $this->assertSame('gh.list_runners', $recent[0]['action']);
        $this->assertSame('ok-two', $recent[0]['message']);
        $this->assertSame('error', $recent[0]['level']);
    }

    #[RunInSeparateProcess]
    public function testClearWipesRotatedFilesAndWritesInfoLine(): void
    {
        file_put_contents($this->dir . '/runnerdeck.log.1', "{\"action\":\"old\"}\n");
        file_put_contents(\RunnerDeck\Core\AppLog::path(), "{\"action\":\"current\"}\n");

        \RunnerDeck\Core\AppLog::clear();

        $this->assertFileDoesNotExist($this->dir . '/runnerdeck.log.1');
        $recent = \RunnerDeck\Core\AppLog::recent(10);
        $this->assertCount(1, $recent);
        $this->assertSame('info', $recent[0]['level']);
        $this->assertSame('log.cleared', $recent[0]['action']);
    }
}
