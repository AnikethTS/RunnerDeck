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
        $this->assertTrue(\RunnerDeck\RunnerPool::isKnownId('runner-base'));
        $this->assertTrue(\RunnerDeck\RunnerPool::isKnownId('runner-2'));
    }

    #[RunInSeparateProcess]
    public function testIsKnownIdRejectsUnconfiguredOrMalformedIds(): void
    {
        $this->assertFalse(\RunnerDeck\RunnerPool::isKnownId('runner-3'));
        $this->assertFalse(\RunnerDeck\RunnerPool::isKnownId('not-a-runner-dir'));
        $this->assertFalse(\RunnerDeck\RunnerPool::isKnownId('../etc/passwd'));
        $this->assertFalse(\RunnerDeck\RunnerPool::isKnownId('runner-'));
    }

    #[RunInSeparateProcess]
    public function testDirForJoinsPoolDirAndId(): void
    {
        $this->assertSame($this->poolDir . '/runner-2', \RunnerDeck\RunnerPool::dirFor('runner-2'));
    }

    #[RunInSeparateProcess]
    public function testAgentNameForBaseUsesBareLabel(): void
    {
        putenv('RUNNERDECK_LABEL=my-label');
        $this->assertSame('my-label', \RunnerDeck\RunnerPool::agentNameFor('runner-base'));
    }

    #[RunInSeparateProcess]
    public function testAgentNameForNumberedSuffixesTheLabel(): void
    {
        putenv('RUNNERDECK_LABEL=my-label');
        $this->assertSame('my-label-2', \RunnerDeck\RunnerPool::agentNameFor('runner-2'));
    }

    public function testTailLogReturnsEmptyForMissingFile(): void
    {
        $this->assertSame([], \RunnerDeck\RunnerPool::tailLog($this->poolDir . '/does-not-exist.log', 10));
    }

    public function testTailLogReturnsLastNLines(): void
    {
        $path = $this->poolDir . '/runner.log';
        file_put_contents($path, implode("\n", range(1, 100)) . "\n");

        $tail = \RunnerDeck\RunnerPool::tailLog($path, 5);

        $this->assertSame(['96', '97', '98', '99', '100'], $tail);
    }

    public function testTailLogReturnsAllLinesWhenFileIsShorterThanRequested(): void
    {
        $path = $this->poolDir . '/runner.log';
        file_put_contents($path, "a\nb\nc\n");

        $this->assertSame(['a', 'b', 'c'], \RunnerDeck\RunnerPool::tailLog($path, 50));
    }

    public function testTailLogReturnsEmptyWhenRequestingZeroLines(): void
    {
        $path = $this->poolDir . '/runner.log';
        file_put_contents($path, "a\nb\n");

        $this->assertSame([], \RunnerDeck\RunnerPool::tailLog($path, 0));
    }

    #[RunInSeparateProcess]
    public function testNextAvailableIdFillsBaseFirst(): void
    {
        rmdir($this->poolDir . '/runner-base');
        rmdir($this->poolDir . '/runner-2');
        rmdir($this->poolDir . '/not-a-runner-dir');
        $this->assertSame('runner-base', \RunnerDeck\RunnerPool::nextAvailableId());
    }

    #[RunInSeparateProcess]
    public function testNextAvailableIdContinuesAfterHighestNumber(): void
    {
        $this->assertSame('runner-3', \RunnerDeck\RunnerPool::nextAvailableId());
    }
}
