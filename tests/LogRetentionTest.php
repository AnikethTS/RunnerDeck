<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class LogRetentionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runnerdeck-logs-' . uniqid();
        mkdir($this->root . '/storage', 0700, true);
        mkdir($this->root . '/pool/runner-1/_diag', 0755, true);
        mkdir($this->root . '/pool/runner-1/_work', 0755, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->root . '/storage/settings.json');
        putenv('RUNNERDECK_POOL_DIR=' . $this->root . '/pool');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_LOG_RETENTION_DAYS');
        \RunnerDeck\Pool\LogRetention::fakeNow(null);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [false, null]);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Pool\LogRetention::fakeNow(null);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_LOG_RETENTION_DAYS');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[RunInSeparateProcess]
    public function testTickIsNoopWhenUnset(): void
    {
        $this->assertSame(0, \RunnerDeck\Pool\LogRetention::tick());
    }

    #[RunInSeparateProcess]
    public function testPruneDirDeletesOldLogsNotWorkOrSymlinks(): void
    {
        $dir = $this->root . '/pool/runner-1';
        $now = 1_700_000_000;
        $old = $now - (10 * 86400);
        $fresh = $now - 3600;
        file_put_contents($dir . '/runner.log', 'old');
        file_put_contents($dir . '/runner.log.1', 'rot');
        file_put_contents($dir . '/_diag/old.txt', 'x');
        file_put_contents($dir . '/_diag/fresh.txt', 'y');
        file_put_contents($dir . '/_work/job.log', 'keep');
        $target = $dir . '/_diag/fresh.txt';
        symlink($target, $dir . '/_diag/link.txt');
        touch($dir . '/runner.log', $old);
        touch($dir . '/runner.log.1', $old);
        touch($dir . '/_diag/old.txt', $old);
        touch($dir . '/_diag/fresh.txt', $fresh);
        touch($dir . '/_work/job.log', $old);

        $removed = \RunnerDeck\Pool\LogRetention::pruneDir($dir, $now - (7 * 86400));
        $this->assertSame(3, $removed);
        $this->assertFileDoesNotExist($dir . '/runner.log');
        $this->assertFileDoesNotExist($dir . '/runner.log.1');
        $this->assertFileDoesNotExist($dir . '/_diag/old.txt');
        $this->assertFileExists($dir . '/_diag/fresh.txt');
        $this->assertFileExists($dir . '/_diag/link.txt');
        $this->assertFileExists($dir . '/_work/job.log');
    }

    #[RunInSeparateProcess]
    public function testTickSkipsRunningSlots(): void
    {
        $dir = $this->root . '/pool/runner-1';
        $now = 1_700_000_000;
        file_put_contents($dir . '/runner.log', 'busy');
        touch($dir . '/runner.log', $now - (10 * 86400));
        putenv('RUNNERDECK_LOG_RETENTION_DAYS=7');
        \RunnerDeck\Pool\LogRetention::fakeNow(fn () => $now);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [true, 999]);

        $this->assertSame(0, \RunnerDeck\Pool\LogRetention::tick());
        $this->assertFileExists($dir . '/runner.log');
    }
}
