<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Action;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Http\Action\Runners as A;
use Tests\Support\HttpActionHarness;

final class RunnerActionsTest extends TestCase
{
    use HttpActionHarness;

    protected function setUp(): void
    {
        $this->setUpHttp();
    }

    protected function tearDown(): void
    {
        $this->tearDownHttp();
    }

    /** @return array<string, array{class-string}> */
    public static function singleRunnerActions(): array
    {
        $names = [
            'StartAction', 'StopAction', 'RestartAction', 'RenameAction', 'DeleteRunnerAction',
            'DrainStopAction', 'RollingRestartAction', 'ForceIdleAction', 'ClearWorkAction',
        ];
        $out = [];
        foreach ($names as $name) {
            $out[$name] = ['RunnerDeck\\Http\\Action\\Runners\\' . $name];
        }
        return $out;
    }

    /** @param class-string $class */
    #[DataProvider('singleRunnerActions')]
    public function testAnUnknownRunnerIsNotFound(string $class): void
    {
        $res = $this->call($class, ['runner' => 'runner-9']);

        $this->assertSame(404, $res['status']);
        $this->assertFalse($res['data']['ok']);
        $this->assertSame('unknown runner: runner-9', $res['data']['message']);
    }

    /** @param class-string $class */
    #[DataProvider('singleRunnerActions')]
    public function testAMalformedRunnerIdIsNotFound(string $class): void
    {
        $res = $this->call($class, ['runner' => '../etc']);

        $this->assertSame(404, $res['status']);
    }

    /** @return array<string, array{class-string, array<string, string>}> */
    public static function actionsThatCheckGithubFirst(): array
    {
        return [
            'stop' => ['RunnerDeck\\Http\\Action\\Runners\\StopAction', []],
            'restart' => ['RunnerDeck\\Http\\Action\\Runners\\RestartAction', []],
            'delete' => ['RunnerDeck\\Http\\Action\\Runners\\DeleteRunnerAction', []],
            'rename' => ['RunnerDeck\\Http\\Action\\Runners\\RenameAction', ['name' => 'new-name']],
        ];
    }

    /**
     * @param class-string $class
     * @param array<string, string> $extra
     */
    #[DataProvider('actionsThatCheckGithubFirst')]
    public function testActionsRefuseWhenGithubCannotConfirmTheRunnerIsIdle(string $class, array $extra): void
    {
        $this->slot('runner-1');

        $res = $this->call($class, ['runner' => 'runner-1'] + $extra);

        $this->assertSame(409, $res['status']);
        $this->assertTrue($res['data']['busy_unknown']);
        $this->assertStringContainsString('force=1', $res['data']['message']);
        $this->assertDirectoryExists($this->httpRoot . '/pool/runner-1');
    }

    /**
     * @param class-string $class
     * @param array<string, string> $extra
     */
    #[DataProvider('actionsThatCheckGithubFirst')]
    public function testActionsRefuseABusyRunnerWithoutForce(string $class, array $extra): void
    {
        $this->slot('runner-1');
        $this->ghRunners([['id' => 7, 'name' => 'acme-1', 'busy' => true]]);

        $res = $this->call($class, ['runner' => 'runner-1'] + $extra);

        $this->assertSame(409, $res['status']);
        $this->assertTrue($res['data']['busy']);
        $this->assertStringContainsString('acme-1 is currently running a job', $res['data']['message']);
    }

    public function testStopWithForceStopsWithoutAskingGithub(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\StopAction::class, ['runner' => 'runner-1', 'force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['ok' => true, 'message' => 'runner-1 not running'], $res['data']);
    }

    public function testStopOfAnIdleRunnerGoesAheadAfterTheGithubCheck(): void
    {
        $this->slot('runner-1');
        $this->ghRunners([['id' => 1, 'name' => 'acme-1', 'busy' => false]]);

        $res = $this->call(A\StopAction::class, ['runner' => 'runner-1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('runner-1 not running', $res['data']['message']);
    }

    public function testStopAllStopsEverySlot(): void
    {
        $this->slot('runner-1');
        $this->slot('runner-2');
        $this->ghRunners([['id' => 1, 'name' => 'acme-1'], ['id' => 2, 'name' => 'acme-2']]);

        $res = $this->call(A\StopAllAction::class);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $this->assertStringContainsString('runner-2 not running', $res['data']['message']);
    }

    public function testStopAllIsBlockedByABusyRunner(): void
    {
        $this->slot('runner-1');
        $this->ghRunners([['id' => 1, 'name' => 'acme-1', 'busy' => true]]);

        $res = $this->call(A\StopAllAction::class);

        $this->assertSame(409, $res['status']);
        $this->assertSame(['acme-1'], $res['data']['busy_runners']);
    }

    public function testStopAllIsBlockedWhenGithubIsUnreachable(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\StopAllAction::class);

        $this->assertSame(409, $res['status']);
        $this->assertTrue($res['data']['busy_unknown']);
    }

    public function testStartReportsWhyTheRunnerCouldNotStart(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\StartAction::class, ['runner' => 'runner-1']);

        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['data']['ok']);
        $this->assertStringContainsString('failed to start runner-1', $res['data']['message']);
    }

    public function testRestartWithForceTriesToStartAgain(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\RestartAction::class, ['runner' => 'runner-1', 'force' => '1']);

        $this->assertFalse($res['data']['ok']);
        $this->assertStringContainsString('failed to start runner-1', $res['data']['message']);
    }

    public function testRenameRequiresAName(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\RenameAction::class, ['runner' => 'runner-1', 'name' => '   ']);

        $this->assertSame(422, $res['status']);
        $this->assertSame('name is required', $res['data']['message']);
    }

    public function testRenameWithForceReportsAGithubFailure(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\RenameAction::class, ['runner' => 'runner-1', 'name' => 'nova', 'force' => '1']);

        $this->assertFalse($res['data']['ok']);
        $this->assertStringContainsString('failed to deregister acme-1', $res['data']['message']);
    }

    public function testDeleteWithForceRemovesTheSlotFromTheDashboard(): void
    {
        $this->slot('runner-1');
        $this->ghRunners([['id' => 1, 'name' => 'acme-1']]);

        $res = $this->call(A\DeleteRunnerAction::class, ['runner' => 'runner-1', 'force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $this->assertDirectoryDoesNotExist($this->httpRoot . '/pool/runner-1');
    }

    public function testForceIdleReportsAGithubFailure(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\ForceIdleAction::class, ['runner' => 'runner-1']);

        $this->assertFalse($res['data']['ok']);
        $this->assertStringContainsString('failed to clear GitHub busy', $res['data']['message']);
    }

    public function testClearWorkWithNothingToClearSucceeds(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\ClearWorkAction::class, ['runner' => 'runner-1']);

        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('no work directory to clear', $res['data']['message']);
    }

    public function testClearWorkRefusesARunningSlot(): void
    {
        $dir = $this->slot('runner-1');
        mkdir($dir . '/_work');
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [true, 4242]);

        $res = $this->call(A\ClearWorkAction::class, ['runner' => 'runner-1']);

        $this->assertSame(409, $res['status']);
        $this->assertFalse($res['data']['ok']);
        $this->assertDirectoryExists($dir . '/_work');
    }

    public function testDrainStopRefusesWhenGithubCannotConfirmIdle(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\DrainStopAction::class, ['runner' => 'runner-1', 'timeout' => '1']);

        $this->assertSame(409, $res['status']);
        $this->assertTrue($res['data']['busy_unknown']);
    }

    public function testDrainStopWithForceStopsAtOnce(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\DrainStopAction::class, ['runner' => 'runner-1', 'force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('runner-1 not running', $res['data']['message']);
    }

    public function testDrainStopOfAnIdleRunnerStopsAfterTheCheck(): void
    {
        $this->slot('runner-1');
        $this->ghRunners([['id' => 1, 'name' => 'acme-1', 'busy' => false]]);

        $res = $this->call(A\DrainStopAction::class, ['runner' => 'runner-1', 'timeout' => '5']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
    }

    public function testDrainStopAllRefusesWhenGithubCannotConfirmIdle(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\DrainStopAllAction::class, ['timeout' => '1']);

        $this->assertSame(409, $res['status']);
    }

    public function testDrainStopAllWithForceStopsEverySlot(): void
    {
        $this->slot('runner-1');
        $this->slot('runner-2');

        $res = $this->call(A\DrainStopAllAction::class, ['force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('runner-2 not running', $res['data']['message']);
    }

    public function testRollingRestartOfOneRunnerWithForce(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\RollingRestartAction::class, ['runner' => 'runner-1', 'force' => '1', 'timeout' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['data']['ok']);
        $this->assertStringContainsString('failed to start runner-1', $res['data']['message']);
    }

    public function testStartAllAndResizeReportDownloadFailures(): void
    {
        $this->slot('runner-1');

        $start = $this->call(A\StartAllAction::class, ['count' => '1']);
        $resize = $this->call(A\ResizeAction::class, ['count' => '1']);

        $this->assertSame(200, $start['status']);
        $this->assertStringContainsString('failed to start runner-1', $start['data']['message']);
        $this->assertSame(200, $resize['status']);
        $this->assertArrayHasKey('message', $resize['data']);
    }

    public function testStartAllClampsTheCountToTheSlotLimits(): void
    {
        $high = $this->call(A\StartAllAction::class, ['count' => '9999']);
        $low = $this->call(A\StartAllAction::class, ['count' => '-4']);

        $this->assertSame(200, $high['status']);
        $this->assertSame(200, $low['status']);
        $this->assertLessThanOrEqual(30, count(glob($this->httpRoot . '/pool/runner-*') ?: []));
    }

    public function testAddRunnerRejectsBadExtraLabels(): void
    {
        $res = $this->call(A\AddRunnerAction::class, ['extra_labels' => 'not valid!']);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('extra labels must be up to 12', $res['data']['message']);
    }

    public function testAddRunnerReportsAGithubFailure(): void
    {
        $res = $this->call(A\AddRunnerAction::class, ['name' => 'extra', 'ephemeral' => '1', 'extra_labels' => 'gpu']);

        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['data']['ok']);
    }
}
