<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RunnerPoolTest extends TestCase
{
    private string $poolDir;

    protected function setUp(): void
    {
        $this->poolDir = sys_get_temp_dir() . '/runner-pool-test-' . uniqid();
        mkdir($this->poolDir . '/runner-base', 0755, true);
        mkdir($this->poolDir . '/runner-2', 0755, true);
        mkdir($this->poolDir . '/not-a-runner-dir', 0755, true);
        putenv('RUNNERDECK_POOL_DIR=' . $this->poolDir);
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_LABEL');
        exec('rm -rf ' . escapeshellarg($this->poolDir));
    }

    #[RunInSeparateProcess]
    public function testIsKnownIdAcceptsConfiguredDirs(): void
    {
        $this->assertTrue(\RunnerPool::isKnownId('runner-base'));
        $this->assertTrue(\RunnerPool::isKnownId('runner-2'));
    }

    #[RunInSeparateProcess]
    public function testIsKnownIdRejectsUnconfiguredOrMalformedIds(): void
    {
        $this->assertFalse(\RunnerPool::isKnownId('runner-3'));
        $this->assertFalse(\RunnerPool::isKnownId('not-a-runner-dir'));
        $this->assertFalse(\RunnerPool::isKnownId('../etc/passwd'));
        $this->assertFalse(\RunnerPool::isKnownId('runner-'));
    }

    #[RunInSeparateProcess]
    public function testDirForJoinsPoolDirAndId(): void
    {
        $this->assertSame($this->poolDir . '/runner-2', \RunnerPool::dirFor('runner-2'));
    }

    #[RunInSeparateProcess]
    public function testAgentNameForBaseUsesBareLabel(): void
    {
        putenv('RUNNERDECK_LABEL=my-label');
        $this->assertSame('my-label', \RunnerPool::agentNameFor('runner-base'));
    }

    #[RunInSeparateProcess]
    public function testAgentNameForNumberedSuffixesTheLabel(): void
    {
        putenv('RUNNERDECK_LABEL=my-label');
        $this->assertSame('my-label-2', \RunnerPool::agentNameFor('runner-2'));
    }

    public function testTailLogReturnsEmptyForMissingFile(): void
    {
        $this->assertSame([], \RunnerPool::tailLog($this->poolDir . '/does-not-exist.log', 10));
    }

    public function testTailLogReturnsLastNLines(): void
    {
        $path = $this->poolDir . '/runner.log';
        file_put_contents($path, implode("\n", range(1, 100)) . "\n");

        $tail = \RunnerPool::tailLog($path, 5);

        $this->assertSame(['96', '97', '98', '99', '100'], $tail);
    }

    public function testTailLogReturnsAllLinesWhenFileIsShorterThanRequested(): void
    {
        $path = $this->poolDir . '/runner.log';
        file_put_contents($path, "a\nb\nc\n");

        $this->assertSame(['a', 'b', 'c'], \RunnerPool::tailLog($path, 50));
    }

    public function testTailLogReturnsEmptyWhenRequestingZeroLines(): void
    {
        $path = $this->poolDir . '/runner.log';
        file_put_contents($path, "a\nb\n");

        $this->assertSame([], \RunnerPool::tailLog($path, 0));
    }
}
