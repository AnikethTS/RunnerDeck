<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DashboardViewTest extends TestCase
{
    public function testHealthBannerHiddenWhenGithubIsOk(): void
    {
        $banner = \RunnerDeck\Ui\DashboardView::healthBanner([
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
        ]);
        $this->assertTrue($banner['hidden']);
        $this->assertSame('', $banner['text']);
    }

    public function testHealthBannerWhenMaintenance(): void
    {
        $banner = \RunnerDeck\Ui\DashboardView::healthBanner([
            'maintenance' => true,
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
        ]);
        $this->assertFalse($banner['hidden']);
        $this->assertStringContainsString('maintenance', $banner['text']);
    }

    public function testHealthBannerWhenHostPressure(): void
    {
        $banner = \RunnerDeck\Ui\DashboardView::healthBanner([
            'pressure' => [
                'ok' => false,
                'reasons' => ['disk 100 MB free (need 1 GiB)'],
            ],
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
        ]);
        $this->assertFalse($banner['hidden']);
        $this->assertStringContainsString('100 MB', $banner['text']);
    }

    public function testHealthBannerWhenAgentBehind(): void
    {
        $banner = \RunnerDeck\Ui\DashboardView::healthBanner([
            'agent_behind_count' => 2,
            'agent_latest' => '2.322.0',
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
        ]);
        $this->assertFalse($banner['hidden']);
        $this->assertStringContainsString('2.322.0', $banner['text']);
        $this->assertStringContainsString('Upgrade agents', $banner['text']);
    }

    public function testHealthBannerWhenRebootReady(): void
    {
        $banner = \RunnerDeck\Ui\DashboardView::healthBanner([
            'reboot_ready' => true,
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
        ]);
        $this->assertFalse($banner['hidden']);
        $this->assertStringContainsString('Safe to reboot', $banner['text']);
    }

    public function testHealthBannerWhenWatchStale(): void
    {
        $banner = \RunnerDeck\Ui\DashboardView::healthBanner([
            'watch_warning' => 'watch-auto-restart.php has not ticked — auto-restart is idle.',
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
        ]);
        $this->assertFalse($banner['hidden']);
        $this->assertStringContainsString('watch-auto-restart.php', $banner['text']);
    }

    public function testHealthBannerWhenAuthStale(): void
    {
        $banner = \RunnerDeck\Ui\DashboardView::healthBanner([
            'auth_stale' => 'GitHub authentication is expired or missing.',
            'health' => ['logged_in' => false, 'org_access_ok' => false, 'message' => 'HTTP 401'],
        ]);
        $this->assertFalse($banner['hidden']);
        $this->assertStringContainsString('expired', $banner['text']);
    }

    public function testHealthBannerWhenNotLoggedIn(): void
    {
        $banner = \RunnerDeck\Ui\DashboardView::healthBanner([
            'health' => ['logged_in' => false, 'org_access_ok' => false, 'message' => 'no gh'],
        ]);
        $this->assertFalse($banner['hidden']);
        $this->assertStringContainsString('no gh', $banner['text']);
    }

    public function testRowHtmlEscapesNamesAndLogs(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => '<script>x</script>',
            'configured' => true,
            'local_running' => false,
            'pid' => null,
            'log_tail' => ['<b>log</b>'],
            'github' => null,
            'crash_flagged' => true,
            'mismatch_flagged' => true,
            'agent_version' => '2.<script>0',
        ]);

        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;log&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('v2.&lt;script&gt;0', $html);
        $this->assertStringContainsString('crashed unexpectedly', $html);
        $this->assertStringContainsString('local/GitHub status disagree', $html);
        $this->assertStringContainsString('data-action="start"', $html);
        $this->assertStringContainsString('data-action="rolling-restart"', $html);
        $this->assertStringContainsString('data-action="pause-auto-restart"', $html);
        $this->assertStringContainsString('Pause AR', $html);
    }

    public function testRowHtmlShowsOlderAgentPackage(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'configured' => true,
            'local_running' => true,
            'pid' => 1,
            'log_tail' => [],
            'github' => null,
            'agent_behind' => true,
        ]);
        $this->assertStringContainsString('older agent package', $html);
    }

    public function testRowHtmlShowsGithubOsChip(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'configured' => true,
            'local_running' => true,
            'pid' => 1,
            'log_tail' => [],
            'github' => [
                'status' => 'online',
                'busy' => false,
                'labels' => [],
                'os' => 'Linux',
            ],
        ]);
        $this->assertStringContainsString('os-chip', $html);
        $this->assertStringContainsString('Linux', $html);
        $this->assertStringContainsString('live-job', $html);
        $this->assertStringContainsString('last-jobs', $html);
    }

    public function testOrphansHtmlListsRemoveButton(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::orphansHtml([
            ['name' => 'gone-box', 'id' => 9, 'status' => 'offline', 'busy' => false],
        ]);
        $this->assertStringContainsString('gone-box', $html);
        $this->assertStringContainsString('remove-orphan', $html);
        $this->assertSame('', \RunnerDeck\Ui\DashboardView::orphansHtml([]));
    }

    public function testRowHtmlShowsEphemeralFlag(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'configured' => true,
            'local_running' => true,
            'pid' => 1,
            'log_tail' => [],
            'github' => null,
            'ephemeral' => true,
            'last_job_failed' => true,
            'last_job_name' => 'lint',
        ]);
        $this->assertStringContainsString('ephemeral', $html);
        $this->assertStringContainsString('last job failed: lint', $html);
    }

    public function testRowHtmlShowsPausedAutoRestart(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'configured' => true,
            'local_running' => false,
            'pid' => null,
            'log_tail' => [],
            'github' => null,
            'auto_restart_paused' => true,
        ]);
        $this->assertStringContainsString('auto-restart paused', $html);
        $this->assertStringContainsString('Resume AR', $html);
    }

    public function testRowHtmlCrashHistoryIsAButton(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'configured' => true,
            'local_running' => true,
            'pid' => 1,
            'log_tail' => [],
            'github' => null,
            'crash_count_7d' => 2,
        ]);

        $this->assertStringContainsString('2 crashes (7d)', $html);
        $this->assertStringContainsString('data-action="crash-history"', $html);
    }

    public function testRowHtmlShowsDiskDrainAndClearWork(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'configured' => true,
            'local_running' => false,
            'pid' => null,
            'log_tail' => [],
            'github' => null,
            'disk_kb' => 2048,
            'can_clear_work' => true,
        ]);

        $this->assertStringContainsString('2.0 MB disk', $html);
        $this->assertStringContainsString('data-action="drain"', $html);
        $this->assertStringContainsString('data-action="clear-work"', $html);
        $this->assertDoesNotMatchRegularExpression('/data-action="clear-work"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/data-action="force-idle"\s+hidden/', $html);
    }

    public function testRowHtmlShowsForceIdleWhenStuckBusy(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'configured' => true,
            'local_running' => false,
            'pid' => null,
            'log_tail' => [],
            'github' => ['status' => 'online', 'busy' => true, 'labels' => []],
            'stuck_busy_flagged' => true,
        ]);
        $this->assertStringContainsString('GitHub busy, process gone', $html);
        $this->assertStringContainsString('data-action="force-idle"', $html);
        $this->assertDoesNotMatchRegularExpression('/data-action="force-idle"[^>]*hidden/', $html);
    }

    public function testRowHtmlOmitsAgentVersionWhenMissing(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::rowHtml([
            'id' => 'runner-1',
            'agent_name' => 'acme-1',
            'configured' => true,
            'local_running' => false,
            'pid' => null,
            'log_tail' => [],
            'github' => null,
        ]);
        $this->assertStringNotContainsString('agent-version', $html);
    }

    public function testStatsCardsIssueWhenGithubUnreachable(): void
    {
        $cards = \RunnerDeck\Ui\DashboardView::statsCards([
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
        $this->assertSame('—', $cards['pool_disk']);
    }

    public function testStatsCardsShowPoolDisk(): void
    {
        $cards = \RunnerDeck\Ui\DashboardView::statsCards([
            'stats' => [
                'running' => 1,
                'total' => 2,
                'avg_cpu_percent' => 1.0,
                'total_rss_kb' => 1024,
                'total_disk_kb' => 2048,
            ],
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
            'system' => [
                'cpu_percent' => 1.0,
                'cpu_cores' => 1,
                'mem_used_kb' => 1024,
                'mem_total_kb' => 2048,
                'mem_percent' => 50.0,
            ],
        ]);
        $this->assertSame('2 MB', $cards['pool_disk']);
        $this->assertSame('across 2 slots', $cards['pool_disk_sub']);
    }

    public function testErrorsPanelEscapesAndListsNewestFirst(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::errorsPanel([
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
        $this->assertStringContainsString('Recent log', $html);
        $this->assertStringContainsString('download_applog.php', $html);
        $this->assertStringContainsString('id="app-log-level"', $html);
        $this->assertStringContainsString('id="btn-clear-applog"', $html);
        $this->assertStringContainsString('data-level="error"', $html);
        $this->assertStringContainsString('(2)', $html);
        $this->assertStringContainsString('failed &lt;one&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;nope&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>nope</b>', $html);
        $this->assertTrue(
            strpos($html, 'gh.list_runners') < strpos($html, 'process.start'),
            'newest entry should appear first'
        );
    }

    public function testPollViewIncludesPhpRowMarkup(): void
    {
        $view = \RunnerDeck\Ui\DashboardView::pollView([
            'generated_at' => 1789160000,
            'health' => ['logged_in' => true, 'org_access_ok' => true, 'message' => 'OK'],
            'runners' => [[
                'id' => 'runner-1',
                'agent_name' => 'acme-1',
                'configured' => true,
                'local_running' => false,
                'pid' => null,
                'log_tail' => [],
                'github' => null,
            ]],
            'stats' => ['running' => 0, 'total' => 1],
            'errors' => [],
        ]);
        $this->assertStringContainsString('data-runner="runner-1"', $view['rows_html']);
        $this->assertStringContainsString('Nothing logged yet.', $view['errors_html']);
        $this->assertSame('', $view['errors_count']);
        $this->assertTrue($view['banner_hidden']);
        $this->assertSame('0', $view['stats']['active']);
        $this->assertSame('', $view['orphans_html']);
    }

    public function testErrorsPanelEmptyState(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::errorsPanel([]);
        $this->assertStringContainsString('Nothing logged yet.', $html);
        $this->assertStringNotContainsString(' open', $html);
    }

    public function testErrorsPanelInfoOnlyDoesNotAutoOpen(): void
    {
        $html = \RunnerDeck\Ui\DashboardView::errorsPanel([
            [
                'time' => '2026-09-22T18:00:00+00:00',
                'action' => 'process.start',
                'message' => 'runner-1 started',
                'level' => 'info',
                'runner' => 'runner-1',
            ],
        ]);
        $this->assertStringContainsString('app-log-info', $html);
        $this->assertStringContainsString('(1)', $html);
        $this->assertStringNotContainsString(' open', $html);
    }
}
