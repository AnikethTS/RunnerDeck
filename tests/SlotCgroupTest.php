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
        $this->assertTrue(\RunnerDeck\Host\SlotCgroup::apply('runner-1', 99));
    }

    #[RunInSeparateProcess]
    public function testApplyFailsOpenWithoutCgroupFs(): void
    {
        putenv('RUNNERDECK_SLOT_CPU_PERCENT=20');
        \RunnerDeck\Host\SlotCgroup::fakeRoot(sys_get_temp_dir() . '/runnerdeck-no-cg-' . uniqid());
        $this->assertFalse(\RunnerDeck\Host\SlotCgroup::apply('runner-1', 99));
        putenv('RUNNERDECK_SLOT_CPU_PERCENT');
        \RunnerDeck\Host\SlotCgroup::fakeRoot(null);
    }

    #[RunInSeparateProcess]
    public function testApplyWritesCpuAndMemoryFilesOnFakeCgroupV2(): void
    {
        $root = sys_get_temp_dir() . '/runnerdeck-cg-' . uniqid();
        mkdir($root, 0755, true);
        file_put_contents($root . '/cgroup.controllers', "cpu memory\n");
        putenv('RUNNERDECK_SLOT_CPU_PERCENT=25');
        putenv('RUNNERDECK_SLOT_MEMORY_MAX_MIB=128');
        \RunnerDeck\Host\SlotCgroup::fakeRoot($root);

        $this->assertTrue(\RunnerDeck\Host\SlotCgroup::apply('runner-1', getmypid()));
        $slot = $root . '/runnerdeck/runner-1';
        $this->assertSame('25000 100000', trim((string) file_get_contents($slot . '/cpu.max')));
        $this->assertSame((string) (128 * 1024 * 1024), trim((string) file_get_contents($slot . '/memory.max')));
        $this->assertSame((string) getmypid(), trim((string) file_get_contents($slot . '/cgroup.procs')));

        putenv('RUNNERDECK_SLOT_CPU_PERCENT');
        putenv('RUNNERDECK_SLOT_MEMORY_MAX_MIB');
        \RunnerDeck\Host\SlotCgroup::fakeRoot(null);
        exec('rm -rf ' . escapeshellarg($root));
    }

    #[RunInSeparateProcess]
    public function testApplyRejectsBadRunnerId(): void
    {
        putenv('RUNNERDECK_SLOT_CPU_PERCENT=20');
        $root = sys_get_temp_dir() . '/runnerdeck-cg-bad-' . uniqid();
        mkdir($root, 0755, true);
        file_put_contents($root . '/cgroup.controllers', "cpu\n");
        \RunnerDeck\Host\SlotCgroup::fakeRoot($root);
        $this->assertFalse(\RunnerDeck\Host\SlotCgroup::apply('../etc', 1));
        putenv('RUNNERDECK_SLOT_CPU_PERCENT');
        \RunnerDeck\Host\SlotCgroup::fakeRoot(null);
        exec('rm -rf ' . escapeshellarg($root));
    }
}
