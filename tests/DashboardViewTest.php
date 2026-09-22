<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DashboardViewTest extends TestCase
{
    public function testHealthBannerHiddenWhenGithubIsOk(): void
    {
        $banner = \RunnerDeck\DashboardView::healthBanner([
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
        ]);
        $this->assertTrue($banner['hidden']);
        $this->assertSame('', $banner['text']);
    }

    public function testHealthBannerWhenNotLoggedIn(): void
    {
        $banner = \RunnerDeck\DashboardView::healthBanner([
            'health' => ['logged_in' => false, 'org_access_ok' => false, 'message' => 'no gh'],
        ]);
        $this->assertFalse($banner['hidden']);
        $this->assertStringContainsString('no gh', $banner['text']);
    }

    public function testRowHtmlEscapesNamesAndLogs(): void
    {
        $html = \RunnerDeck\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => '<script>x</script>',
            'configured' => true,
            'local_running' => false,
            'pid' => null,
            'log_tail' => ['<b>log</b>'],
            'github' => null,
            'crash_flagged' => true,
            'mismatch_flagged' => true,
        ]);

        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;log&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('crashed unexpectedly', $html);
        $this->assertStringContainsString('local/GitHub status disagree', $html);
        $this->assertStringContainsString('data-action="start"', $html);
    }

    public function testStatsCardsIssueWhenGithubUnreachable(): void
    {
        $cards = \RunnerDeck\DashboardView::statsCards([
            'stats' => ['running' => 0, 'total' => 2, 'avg_cpu_percent' => null, 'total_rss_kb' => null],
            'health' => ['logged_in' => false, 'org_access_ok' => false, 'message' => 'denied'],
            'system' => [
                'cpu_percent' => 10.0,
                'cpu_cores' => 2,
                'mem_used_kb' => 1024,
                'mem_total_kb' => 2048,
                'mem_percent' => 70.0,
            ],
        ]);
        $this->assertSame('0', $cards['active']);
        $this->assertSame('Issue', $cards['github']);
        $this->assertSame('denied', $cards['github_sub']);
        $this->assertStringContainsString('stat-warning', $cards['sys_mem_class']);
    }

    public function testErrorsPanelEscapesAndListsNewestFirst(): void
    {
        $html = \RunnerDeck\DashboardView::errorsPanel([
            [
                'time' => '2026-09-22T18:00:00+00:00',
                'action' => 'process.start',
                'message' => 'failed <one>',
                'runner' => 'runner-1',
            ],
            [
                'time' => '2026-09-22T18:01:00+00:00',
                'action' => 'gh.list_runners',
                'message' => 'denied',
                'stderr' => '<b>nope</b>',
            ],
        ]);
        $this->assertStringContainsString('open', $html);
        $this->assertStringContainsString('(2)', $html);
        $this->assertStringContainsString('failed &lt;one&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;nope&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>nope</b>', $html);
        $this->assertTrue(
            strpos($html, 'gh.list_runners') < strpos($html, 'process.start'),
            'newest entry should appear first'
        );
    }

    public function testErrorsPanelEmptyState(): void
    {
        $html = \RunnerDeck\DashboardView::errorsPanel([]);
        $this->assertStringContainsString('No errors logged yet.', $html);
        $this->assertStringNotContainsString(' open', $html);
    }
}
