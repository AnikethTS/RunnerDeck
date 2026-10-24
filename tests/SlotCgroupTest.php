<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SlotCgroupTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testApplyIsNoopWhenCapsAreOff(): void
    {
        $this->assertTrue(\RunnerDeck\SlotCgroup::apply('runner-1', 99));
    }

    #[RunInSeparateProcess]
    public function testApplyFailsOpenWithoutCgroupFs(): void
    {
        putenv('RUNNERDECK_SLOT_CPU_PERCENT=20');
        \RunnerDeck\SlotCgroup::fakeRoot(sys_get_temp_dir() . '/runnerdeck-no-cg-' . uniqid());
        $this->assertFalse(\RunnerDeck\SlotCgroup::apply('runner-1', 99));
        putenv('RUNNERDECK_SLOT_CPU_PERCENT');
        \RunnerDeck\SlotCgroup::fakeRoot(null);
    }
}
