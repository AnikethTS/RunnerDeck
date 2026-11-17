<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Host\HostMode;
use RunnerDeck\Http\Action\Administration as Admin;
use RunnerDeck\Http\Action\Monitoring as Mon;
use RunnerDeck\Lifecycle\CrashState;
use RunnerDeck\Logging\AppLog;

final class HttpAdminActionsTest extends TestCase
{
    use HttpActionHarness;

    protected function setUp(): void
    {
        $this->setUpHttp();
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_CHECK_UPDATES');
        $this->tearDownHttp();
    }

    public function testMaintenanceOffClearsTheFlag(): void
    {
        HostMode::setMaintenance(true);

        $res = $this->call(Admin\MaintenanceAction::class, ['enabled' => '0']);

        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['data']['maintenance']);
        $this->assertFalse(HostMode::isMaintenance());
    }

    public function testMaintenanceOnDrainStopsThePoolAndSetsTheFlag(): void
    {
        $this->slot('runner-1');

        $res = $this->call(Admin\MaintenanceAction::class, ['enabled' => '1', 'force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['maintenance']);
        $this->assertStringContainsString('runner-1 not running', $res['data']['message']);
        $this->assertTrue(HostMode::isMaintenance());
    }

    public function testMaintenanceOnWaitsForIdleRunnersAndRefusesWhenItCannotTell(): void
    {
        $this->slot('runner-1');

        $res = $this->call(Admin\MaintenanceAction::class, ['enabled' => '1', 'timeout' => '1']);

        $this->assertSame(409, $res['status']);
        $this->assertTrue($res['data']['busy_unknown']);
    }

    public function testSafeRebootStopsThePoolAndMarksTheHostReady(): void
    {
        $this->slot('runner-1');

        $res = $this->call(Admin\SafeRebootAction::class, ['force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['reboot_ready']);
        $this->assertTrue(HostMode::isRebootReady());
    }

    public function testSafeRebootRefusesWhenGithubCannotConfirmIdle(): void
    {
        $this->slot('runner-1');

        $res = $this->call(Admin\SafeRebootAction::class, ['timeout' => '1']);

        $this->assertSame(409, $res['status']);
        $this->assertFalse(HostMode::isRebootReady());
    }

    public function testPauseAndResumeAutoRestart(): void
    {
        $this->slot('runner-1');

        $paused = $this->call(Admin\PauseAutoRestartAction::class, ['runner' => 'runner-1']);
        $this->assertTrue($paused['data']['paused']);
        $this->assertSame('runner-1 auto-restart paused', $paused['data']['message']);
        $this->assertTrue(CrashState::export()['runner-1']['autoRestartPaused']);

        $resumed = $this->call(Admin\PauseAutoRestartAction::class, ['runner' => 'runner-1', 'paused' => '0']);
        $this->assertFalse($resumed['data']['paused']);
        $this->assertFalse(CrashState::export()['runner-1']['autoRestartPaused']);
    }

    public function testPauseAutoRestartRejectsAnUnknownRunner(): void
    {
        $res = $this->call(Admin\PauseAutoRestartAction::class, ['runner' => 'runner-3']);

        $this->assertSame(404, $res['status']);
    }

    public function testClearApplogEmptiesTheLog(): void
    {
        AppLog::info('test.event', 'something happened');
        $this->assertNotEmpty(AppLog::recent());

        $res = $this->call(Admin\ClearApplogAction::class);

        $this->assertSame(['ok' => true, 'message' => 'Log cleared'], $res['data']);
        $left = AppLog::recent();
        $this->assertCount(1, $left);
        $this->assertSame('log.cleared', $left[0]['action']);
    }

    public function testTestWebhookNeedsAValidUrl(): void
    {
        foreach (['', 'ftp://example.com', 'not a url'] as $url) {
            $res = $this->call(Admin\TestWebhookAction::class, ['crash_webhook_url' => $url]);

            $this->assertSame(422, $res['status'], $url);
            $this->assertStringContainsString('starting with http', $res['data']['message']);
        }
    }

    public function testTestWebhookReportsDelivery(): void
    {
        $this->fakeGh(fn (): array => ['code' => 0, 'stdout' => '', 'stderr' => '']);
        $sent = $this->call(Admin\TestWebhookAction::class, ['crash_webhook_url' => 'https://hooks.example/test']);
        $this->assertSame(200, $sent['status']);
        $this->assertSame('Test notification sent.', $sent['data']['message']);

        $this->fakeGh(fn (): array => ['code' => 22, 'stdout' => '', 'stderr' => 'HTTP 500']);
        $failed = $this->call(Admin\TestWebhookAction::class, ['crash_webhook_url' => 'https://hooks.example/test']);
        $this->assertSame(502, $failed['status']);
        $this->assertSame('Delivery failed: HTTP 500', $failed['data']['message']);
    }

    public function testCheckUpdatesIsOffUnlessEnabled(): void
    {
        $res = $this->call(Admin\CheckUpdatesAction::class);

        $this->assertSame(403, $res['status']);
        $this->assertSame('update checks are disabled', $res['data']['message']);
    }

    public function testCheckUpdatesReportsTheLatestRelease(): void
    {
        putenv('RUNNERDECK_CHECK_UPDATES=1');
        $this->fakeGh(fn (): array => ['code' => 0, 'stdout' => '{"tag_name":"v99.0.0"}', 'stderr' => '']);

        $res = $this->call(Admin\CheckUpdatesAction::class);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $this->assertSame('99.0.0', $res['data']['latest']);
        $this->assertTrue($res['data']['update_available']);
    }

    public function testHistoryReportsRecentSamples(): void
    {
        $res = $this->call(Mon\HistoryAction::class);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $this->assertSame([], $res['data']['samples']);
    }

    public function testSystemReportsHostStats(): void
    {
        $res = $this->call(Mon\SystemAction::class);

        $this->assertTrue($res['data']['ok']);
        $this->assertArrayHasKey('mem_total_kb', $res['data']['system']);
    }

    public function testLogReturnsTheTailOfARunnersLog(): void
    {
        $dir = $this->slot('runner-1');
        file_put_contents($dir . '/runner.log', "one\ntwo\nthree\n");

        $res = $this->call(Mon\LogAction::class, [], ['runner' => 'runner-1', 'lines' => '2']);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['two', 'three'], array_values($res['data']['lines']));
    }

    public function testLogRejectsAnUnknownRunner(): void
    {
        $res = $this->call(Mon\LogAction::class, [], ['runner' => 'runner-8']);

        $this->assertSame(404, $res['status']);
        $this->assertSame('unknown runner: runner-8', $res['data']['message']);
    }

    public function testQueueAndStatusNeedAConfiguredApp(): void
    {
        putenv('RUNNERDECK_ORG');

        $queue = $this->call(Mon\QueueAction::class);
        $status = $this->call(Mon\StatusAction::class);

        $this->assertSame(409, $queue['status']);
        $this->assertSame(409, $status['status']);
        $this->assertSame('RunnerDeck is not configured yet', $status['data']['message']);
    }

    public function testQueueExplainsWhenGhIsNotSignedIn(): void
    {
        $res = $this->call(Mon\QueueAction::class);

        $this->assertSame(200, $res['status']);
        $this->assertSame(0, $res['data']['count']);
    }
}
