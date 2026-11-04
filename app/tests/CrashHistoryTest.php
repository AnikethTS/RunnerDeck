<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CrashHistoryTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/runnerdeck-crashhistory-test-' . uniqid() . '/history.sqlite';
        putenv('RUNNERDECK_HISTORY_FILE=' . $this->dbFile);
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_HISTORY_FILE');
        @unlink($this->dbFile);
        @rmdir(dirname($this->dbFile));
    }

    #[RunInSeparateProcess]
    public function testCountsByRunnerReturnsEmptyArrayWhenNothingRecorded(): void
    {
        $this->assertSame([], \RunnerDeck\Pool\CrashHistory::countsByRunner());
    }

    #[RunInSeparateProcess]
    public function testRecordThenCountsByRunnerRoundTrips(): void
    {
        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');
        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');
        \RunnerDeck\Pool\CrashHistory::record('runner-2', 'acme-2');

        $counts = \RunnerDeck\Pool\CrashHistory::countsByRunner();

        $this->assertSame(2, $counts['runner-1']);
        $this->assertSame(1, $counts['runner-2']);
    }

    #[RunInSeparateProcess]
    public function testCountsByRunnerExcludesEventsOlderThanRetentionWindow(): void
    {
        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');

        $db = new PDO('sqlite:' . $this->dbFile);
        $db->prepare('UPDATE crash_events SET ts = ?')->execute([time() - (8 * 24 * 60 * 60)]);

        $this->assertSame([], \RunnerDeck\Pool\CrashHistory::countsByRunner());
    }

    #[RunInSeparateProcess]
    public function testRecordIsBestEffortWhenPathIsUnwritable(): void
    {
        putenv('RUNNERDECK_HISTORY_FILE=/dev/null/runnerdeck-unwritable/history.sqlite');

        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');

        $this->assertSame([], \RunnerDeck\Pool\CrashHistory::countsByRunner());
    }

    #[RunInSeparateProcess]
    public function testSharesTheSameDatabaseFileAsHistory(): void
    {
        \RunnerDeck\Host\History::record(1.0, 1);
        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');

        $db = new PDO('sqlite:' . $this->dbFile);
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('samples', $tables);
        $this->assertContains('crash_events', $tables);
    }

    #[RunInSeparateProcess]
    public function testTimestampsByRunnerAreNewestFirst(): void
    {
        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');
        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');
        $db = new PDO('sqlite:' . $this->dbFile);
        $db->exec('UPDATE crash_events SET ts = ts - 10 WHERE rowid = (SELECT MIN(rowid) FROM crash_events)');

        $times = \RunnerDeck\Pool\CrashHistory::timestampsByRunner();

        $this->assertCount(2, $times['runner-1']);
        $this->assertGreaterThan($times['runner-1'][1], $times['runner-1'][0]);
    }

    #[RunInSeparateProcess]
    public function testCountsByUtcDayFillsZeros(): void
    {
        \RunnerDeck\Pool\CrashHistory::record('runner-1', 'acme-1');
        $days = \RunnerDeck\Pool\CrashHistory::countsByUtcDay(7);
        $this->assertCount(7, $days);
        $this->assertSame(gmdate('Y-m-d'), $days[6]['day']);
        $this->assertSame(1, $days[6]['count']);
        $this->assertSame(0, $days[0]['count']);
    }
}
