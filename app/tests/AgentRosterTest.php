<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AgentRosterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-roster-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testOrphansAreRosterNamesMissingLocally(): void
    {
        \RunnerDeck\GitHub\AgentRoster::remember('acme-1');
        \RunnerDeck\GitHub\AgentRoster::remember('gone-box');
        $orphans = \RunnerDeck\GitHub\AgentRoster::orphans([
            'acme-1' => ['id' => 1, 'status' => 'online', 'busy' => false, 'labels' => [], 'os' => ''],
            'gone-box' => ['id' => 2, 'status' => 'offline', 'busy' => false, 'labels' => [], 'os' => ''],
        ], ['acme-1']);

        $this->assertCount(1, $orphans);
        $this->assertSame('gone-box', $orphans[0]['name']);
        $this->assertSame(2, $orphans[0]['id']);
    }

    #[RunInSeparateProcess]
    public function testForgetRemovesName(): void
    {
        \RunnerDeck\GitHub\AgentRoster::remember('gone-box');
        \RunnerDeck\GitHub\AgentRoster::forget('gone-box');
        $this->assertSame([], \RunnerDeck\GitHub\AgentRoster::orphans([
            'gone-box' => ['id' => 2, 'status' => 'offline', 'busy' => false, 'labels' => [], 'os' => ''],
        ], []));
    }
}
