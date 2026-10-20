<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RunnerDeck\RunnerInfo;

final class RollingUpgradeTest extends TestCase
{
    public function testSkipsUnconfiguredSlots(): void
    {
        $r = new RunnerInfo(
            id: 'runner-1',
            dir: '/tmp/missing-slot',
            configured: false,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );

        $result = \RunnerDeck\RollingUpgrade::run([$r], true, 10);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('No configured runners', $result['message']);
    }
}
