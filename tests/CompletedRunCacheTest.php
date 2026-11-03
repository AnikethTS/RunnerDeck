<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\GitHub\CompletedRunCache;

final class CompletedRunCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-run-cache-' . uniqid();
        mkdir($this->dir, 0755, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testMissingKeyIsNull(): void
    {
        $this->assertNull(CompletedRunCache::get('acme/app#1#2026-01-01T00:00:00Z'));
    }

    #[RunInSeparateProcess]
    public function testStoredJobsComeBack(): void
    {
        $jobs = [['id' => 9, 'name' => 'build', 'conclusion' => 'success']];

        CompletedRunCache::put('acme/app#1#t', $jobs);

        $this->assertSame($jobs, CompletedRunCache::get('acme/app#1#t'));
        $this->assertNull(CompletedRunCache::get('acme/app#1#other'));
    }

    #[RunInSeparateProcess]
    public function testAnEmptyJobListIsAHitNotAMiss(): void
    {
        CompletedRunCache::put('acme/app#2#t', []);

        $this->assertSame([], CompletedRunCache::get('acme/app#2#t'));
    }

    #[RunInSeparateProcess]
    public function testOldestEntriesAreDroppedPastTheLimit(): void
    {
        for ($i = 1; $i <= 90; $i++) {
            CompletedRunCache::put("acme/app#{$i}#t", [['id' => $i]]);
        }

        $this->assertNull(CompletedRunCache::get('acme/app#1#t'));
        $this->assertNull(CompletedRunCache::get('acme/app#10#t'));
        $this->assertSame([['id' => 90]], CompletedRunCache::get('acme/app#90#t'));
        $this->assertSame([['id' => 11]], CompletedRunCache::get('acme/app#11#t'));
    }
}
