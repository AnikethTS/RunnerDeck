<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/runnerdeck-history-test-' . uniqid() . '/history.sqlite';
        putenv('RUNNERDECK_HISTORY_FILE=' . $this->dbFile);
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_HISTORY_FILE');
        @unlink($this->dbFile);
        @rmdir(dirname($this->dbFile));
    }

    #[RunInSeparateProcess]
    public function testRecentReturnsEmptyArrayWhenNothingRecorded(): void
    {
        $this->assertSame([], \RunnerDeck\History::recent());
    }

    #[RunInSeparateProcess]
    public function testRecordThenRecentRoundTrips(): void
    {
        \RunnerDeck\History::record(42.5, 102400);

        $samples = \RunnerDeck\History::recent();

        $this->assertCount(1, $samples);
        $this->assertSame(42.5, $samples[0]['avg_cpu']);
        $this->assertSame(102400, $samples[0]['total_rss_kb']);
        $this->assertIsInt($samples[0]['ts']);
    }

    #[RunInSeparateProcess]
    public function testRecentExcludesSamplesOlderThanRetentionWindow(): void
    {
        \RunnerDeck\History::record(10.0, 1000);

        $db = new PDO('sqlite:' . $this->dbFile);
        $db->prepare('UPDATE samples SET ts = ?')->execute([time() - 7200]);

        $this->assertSame([], \RunnerDeck\History::recent());
    }

    #[RunInSeparateProcess]
    public function testRecordIsBestEffortWhenPathIsUnwritable(): void
    {
        putenv('RUNNERDECK_HISTORY_FILE=/nonexistent-root-only-path/history.sqlite');

        \RunnerDeck\History::record(1.0, 1);

        $this->assertSame([], \RunnerDeck\History::recent());
    }

    public function testPolylineSvgPlaceholderWhenUnavailable(): void
    {
        $svg = \RunnerDeck\History::polylineSvg([1.0, 2.0], 0.0, 100.0, true);
        $this->assertStringContainsString('unavailable', $svg);
        $this->assertStringNotContainsString('polyline', $svg);
    }

    public function testPolylineSvgNeedsTwoSamples(): void
    {
        $svg = \RunnerDeck\History::polylineSvg([12.0], 0.0, 100.0, false);
        $this->assertStringContainsString('collecting data', $svg);
    }

    public function testPolylineSvgPointsScaleToViewBox(): void
    {
        $svg = \RunnerDeck\History::polylineSvg([0.0, 100.0], 0.0, 100.0, false);
        $this->assertStringContainsString('0.0,60.0', $svg);
        $this->assertStringContainsString('300.0,0.0', $svg);
        $this->assertStringContainsString('<polyline', $svg);
    }
}
