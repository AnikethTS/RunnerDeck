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
        @unlink(dirname($this->dbFile) . '/history_chart.json');
        @rmdir(dirname($this->dbFile));
    }

    #[RunInSeparateProcess]
    public function testRecentReturnsEmptyArrayWhenNothingRecorded(): void
    {
        $this->assertSame([], \RunnerDeck\Telemetry\History::recent());
    }

    #[RunInSeparateProcess]
    public function testRecordThenRecentRoundTrips(): void
    {
        \RunnerDeck\Telemetry\History::record(42.5, 102400);

        $samples = \RunnerDeck\Telemetry\History::recent();

        $this->assertCount(1, $samples);
        $this->assertSame(42.5, $samples[0]['avg_cpu']);
        $this->assertSame(102400, $samples[0]['total_rss_kb']);
        $this->assertNull($samples[0]['disk_free_kb']);
        $this->assertIsInt($samples[0]['ts']);
    }

    #[RunInSeparateProcess]
    public function testRecordKeepsOneSampleInsideTheWindow(): void
    {
        \RunnerDeck\Telemetry\History::record(1.0, 1000);
        \RunnerDeck\Telemetry\History::record(9.0, 9000);

        $samples = \RunnerDeck\Telemetry\History::recent();
        $this->assertCount(1, $samples);
        $this->assertSame(1.0, $samples[0]['avg_cpu']);
    }

    #[RunInSeparateProcess]
    public function testRecordAllowsASampleAfterTheWindow(): void
    {
        \RunnerDeck\Telemetry\History::record(1.0, 1000);
        $db = new PDO('sqlite:' . $this->dbFile);
        $db->prepare('UPDATE samples SET ts = ?')->execute([time() - \RunnerDeck\Telemetry\History::SAMPLE_SECONDS]);
        \RunnerDeck\Telemetry\History::record(9.0, 9000);

        $samples = \RunnerDeck\Telemetry\History::recent();
        $this->assertCount(2, $samples);
        $this->assertSame(9.0, $samples[1]['avg_cpu']);
    }

    #[RunInSeparateProcess]
    public function testHistoryViewReusesTheChartUntilTheWindowPasses(): void
    {
        \RunnerDeck\Telemetry\History::record(10.0, 1000);
        $first = \RunnerDeck\Presentation\Dashboard::historyView();
        $db = new PDO('sqlite:' . $this->dbFile);
        $db->prepare('UPDATE samples SET avg_cpu = 99')->execute();
        $second = \RunnerDeck\Presentation\Dashboard::historyView();
        $this->assertSame($first['cpu_now'], $second['cpu_now']);

        $path = dirname($this->dbFile) . '/history_chart.json';
        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($raw);
        $raw['built_at'] = time() - \RunnerDeck\Telemetry\History::SAMPLE_SECONDS - 1;
        file_put_contents($path, (string) json_encode($raw));
        $third = \RunnerDeck\Presentation\Dashboard::historyView();
        $this->assertSame('99.0%', $third['cpu_now']);
    }

    #[RunInSeparateProcess]
    public function testRecordStoresDiskFreeKb(): void
    {
        \RunnerDeck\Telemetry\History::record(1.0, 1000, 2048);
        $samples = \RunnerDeck\Telemetry\History::recent();
        $this->assertSame(2048, $samples[0]['disk_free_kb']);
    }

    #[RunInSeparateProcess]
    public function testRecentDayKeepsSamplesOlderThanOneHour(): void
    {
        \RunnerDeck\Telemetry\History::record(10.0, 1000);
        $db = new PDO('sqlite:' . $this->dbFile);
        $db->prepare('UPDATE samples SET ts = ?')->execute([time() - 7200]);
        $this->assertSame([], \RunnerDeck\Telemetry\History::recent());
        $this->assertCount(1, \RunnerDeck\Telemetry\History::recent(\RunnerDeck\Telemetry\History::DAY_SECONDS));
    }

    public function testDownsampleKeepsEndpoints(): void
    {
        $samples = [];
        for ($i = 0; $i < 20; $i++) {
            $samples[] = ['i' => $i];
        }
        $out = \RunnerDeck\Telemetry\History::downsample($samples, 5);
        $this->assertCount(5, $out);
        $this->assertSame(0, $out[0]['i']);
        $this->assertSame(19, $out[4]['i']);
    }

    #[RunInSeparateProcess]
    public function testRecentExcludesSamplesOlderThanRetentionWindow(): void
    {
        \RunnerDeck\Telemetry\History::record(10.0, 1000);

        $db = new PDO('sqlite:' . $this->dbFile);
        $db->prepare('UPDATE samples SET ts = ?')->execute([time() - 7200]);

        $this->assertSame([], \RunnerDeck\Telemetry\History::recent());
    }

    #[RunInSeparateProcess]
    public function testRecordIsBestEffortWhenPathIsUnwritable(): void
    {
        putenv('RUNNERDECK_HISTORY_FILE=/dev/null/runnerdeck-unwritable/history.sqlite');

        \RunnerDeck\Telemetry\History::record(1.0, 1);

        $this->assertSame([], \RunnerDeck\Telemetry\History::recent());
    }

    public function testPolylineSvgPlaceholderWhenUnavailable(): void
    {
        $svg = \RunnerDeck\Telemetry\History::polylineSvg([1.0, 2.0], 0.0, 100.0, true);
        $this->assertStringContainsString('unavailable', $svg);
        $this->assertStringNotContainsString('polyline', $svg);
    }

    public function testPolylineSvgNeedsTwoSamples(): void
    {
        $svg = \RunnerDeck\Telemetry\History::polylineSvg([12.0], 0.0, 100.0, false);
        $this->assertStringContainsString('collecting data', $svg);
    }

    public function testPolylineSvgPointsScaleToViewBox(): void
    {
        $svg = \RunnerDeck\Telemetry\History::polylineSvg([0.0, 100.0], 0.0, 100.0, false, '%', 0);
        $this->assertStringContainsString('42.0,92.0', $svg);
        $this->assertStringContainsString('390.0,10.0', $svg);
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('100%', $svg);
        $this->assertStringContainsString('0%', $svg);
        $this->assertStringContainsString('history-rule', $svg);
        $this->assertStringContainsString('now', $svg);
        $this->assertStringContainsString('-1h', $svg);
    }

    public function testBarSvgRendersCounts(): void
    {
        $svg = \RunnerDeck\Telemetry\History::barSvg([
            ['label' => '2026-10-25', 'count' => 0],
            ['label' => '2026-10-26', 'count' => 3],
        ]);
        $this->assertStringContainsString('history-bar', $svg);
        $this->assertStringContainsString('10-26', $svg);
    }

    public function testSummaryFormatsNowAndRange(): void
    {
        $sum = \RunnerDeck\Telemetry\History::summary([8.0, 12.5, 10.0], '%', 1);
        $this->assertSame('10.0%', $sum['now']);
        $this->assertSame('8.0%–12.5% this hour', $sum['span']);
    }

    public function testSummaryEmpty(): void
    {
        $this->assertSame(['now' => '—', 'span' => ''], \RunnerDeck\Telemetry\History::summary([], '%', 1));
    }
}
