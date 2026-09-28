<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class AgentVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-agentver-' . uniqid();
        mkdir($this->dir . '/bin', 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testReadsAgentVersionFromRunnerJson(): void
    {
        file_put_contents($this->dir . '/.runner', json_encode([
            'agentName' => 'acme-1',
            'agentVersion' => '2.321.0',
        ]));

        $this->assertSame('2.321.0', \RunnerDeck\AgentVersion::read($this->dir));
    }

    public function testPrefersRunnerJsonOverListenerDeps(): void
    {
        file_put_contents($this->dir . '/.runner', json_encode(['agentVersion' => '2.300.0']));
        file_put_contents(
            $this->dir . '/bin/Runner.Listener.deps.json',
            json_encode(['targets' => ['.NETCoreApp,Version=v8.0' => ['Runner.Listener/2.321.0' => []]]])
        );

        $this->assertSame('2.300.0', \RunnerDeck\AgentVersion::read($this->dir));
    }

    public function testFallsBackToListenerDepsJson(): void
    {
        file_put_contents(
            $this->dir . '/bin/Runner.Listener.deps.json',
            json_encode(['targets' => ['.NETCoreApp,Version=v8.0' => ['Runner.Listener/2.321.0' => []]]])
        );

        $this->assertSame('2.321.0', \RunnerDeck\AgentVersion::read($this->dir));
    }

    public function testRejectsNonVersionStringsInRunnerJson(): void
    {
        file_put_contents($this->dir . '/.runner', json_encode([
            'agentName' => 'acme-1',
            'version' => 'https://github.com/example',
        ]));

        $this->assertNull(\RunnerDeck\AgentVersion::read($this->dir));
    }

    public function testFallsBackToListenerDepsTextWhenJsonIsBroken(): void
    {
        file_put_contents(
            $this->dir . '/bin/Runner.Listener.deps.json',
            'not-json Runner.Listener/2.311.0 trailing'
        );

        $this->assertSame('2.311.0', \RunnerDeck\AgentVersion::read($this->dir));
    }

    public function testReturnsNullWhenNothingReadable(): void
    {
        $this->assertNull(\RunnerDeck\AgentVersion::read($this->dir));
    }
}
