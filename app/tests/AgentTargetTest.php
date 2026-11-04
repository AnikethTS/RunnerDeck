<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AgentTargetTest extends TestCase
{
    protected function tearDown(): void
    {
        \RunnerDeck\GitHub\AgentTarget::fake(null);
        putenv('RUNNERDECK_AGENT_SHA');
    }

    #[RunInSeparateProcess]
    public function testAnnotateMarksOlderLocalVersions(): void
    {
        \RunnerDeck\GitHub\AgentTarget::fake(fn () => '2.322.0');
        $out = \RunnerDeck\GitHub\AgentTarget::annotate([
            ['id' => 'runner-1', 'agent_version' => '2.321.0'],
            ['id' => 'runner-2', 'agent_version' => '2.322.0'],
        ]);
        $this->assertSame('2.322.0', $out['latest']);
        $this->assertSame(1, $out['behind_count']);
        $this->assertTrue($out['runners'][0]['agent_behind']);
        $this->assertFalse($out['runners'][1]['agent_behind']);
    }

    #[RunInSeparateProcess]
    public function testUnknownLatestFailsOpen(): void
    {
        \RunnerDeck\GitHub\AgentTarget::fake(fn () => null);
        $out = \RunnerDeck\GitHub\AgentTarget::annotate([
            ['id' => 'runner-1', 'agent_version' => '2.321.0'],
        ]);
        $this->assertNull($out['latest']);
        $this->assertSame(0, $out['behind_count']);
        $this->assertFalse($out['runners'][0]['agent_behind']);
    }

    #[RunInSeparateProcess]
    public function testPinSuppressesBehindBanner(): void
    {
        putenv('RUNNERDECK_AGENT_SHA=' . str_repeat('ab', 32));
        \RunnerDeck\GitHub\AgentTarget::fake(fn () => '2.322.0');
        $out = \RunnerDeck\GitHub\AgentTarget::annotate([
            ['id' => 'runner-1', 'agent_version' => '2.321.0'],
        ]);
        $this->assertSame(0, $out['behind_count']);
        $this->assertFalse($out['runners'][0]['agent_behind']);
        putenv('RUNNERDECK_AGENT_SHA');
    }
}
