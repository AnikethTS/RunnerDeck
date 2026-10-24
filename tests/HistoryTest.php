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
        $this->assertSame([], \RunnerDeck\Host\History::recent());
    }

    #[RunInSeparateProcess]
    public function testRecordThenRecentRoundTrips(): void
    {
        \RunnerDeck\Host\History::record(42.5, 102400);

        $samples = \RunnerDeck\Host\History::recent();

        $this->assertCount(1, $samples);
        $this->assertSame(42.5, $samples[0]['avg_cpu']);
        $this->assertSame(102400, $samples[0]['total_rss_kb']);
        $this->assertIsInt($samples[0]['ts']);
    }

    #[RunInSeparateProcess]
    public function testRecentExcludesSamplesOlderThanRetentionWindow(): void
    {
        \RunnerDeck\Host\History::record(10.0, 1000);

        $db = new PDO('sqlite:' . $this->dbFile);
        $db->prepare('UPDATE samples SET ts = ?')->execute([time() - 7200]);

        $this->assertSame([], \RunnerDeck\Host\History::recent());
    }

    #[RunInSeparateProcess]
    public function testRecordIsBestEffortWhenPathIsUnwritable(): void
    {
        putenv('RUNNERDECK_HISTORY_FILE=/nonexistent-root-only-path/history.sqlite');

        \RunnerDeck\Host\History::record(1.0, 1);

        $this->assertSame([], \RunnerDeck\Host\History::recent());
    }

    public function testPolylineSvgPlaceholderWhenUnavailable(): void
    {
        $svg = \RunnerDeck\Host\History::polylineSvg([1.0, 2.0], 0.0, 100.0, true);
        $this->assertStringContainsString('unavailable', $svg);
        $this->assertStringNotContainsString('polyline', $svg);
    }

    public function testPolylineSvgNeedsTwoSamples(): void
    {
        $svg = \RunnerDeck\Host\History::polylineSvg([12.0], 0.0, 100.0, false);
        $this->assertStringContainsString('collecting data', $svg);
    }

    public function testPolylineSvgPointsScaleToViewBox(): void
    {
        $svg = \RunnerDeck\Host\History::polylineSvg([0.0, 100.0], 0.0, 100.0, false, '%', 0);
        $this->assertStringContainsString('42.0,92.0', $svg);
        $this->assertStringContainsString('390.0,10.0', $svg);
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('100%', $svg);
        $this->assertStringContainsString('0%', $svg);
        $this->assertStringContainsString('history-rule', $svg);
        $this->assertStringContainsString('now', $svg);
    }

    public function testSummaryFormatsNowAndRange(): void
    {
        $sum = \RunnerDeck\Host\History::summary([8.0, 12.5, 10.0], '%', 1);
        $this->assertSame('10.0%', $sum['now']);
        $this->assertSame('8.0%–12.5% this hour', $sum['span']);
    }

    public function testSummaryEmpty(): void
    {
        $this->assertSame(['now' => '—', 'span' => ''], \RunnerDeck\Host\History::summary([], '%', 1));
    }
}
