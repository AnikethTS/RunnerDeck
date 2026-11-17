<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Action;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RunnerDeck\GitHub\AgentRoster;
use RunnerDeck\Http\Action\Runners as A;
use Tests\Support\HttpActionHarness;

final class BulkActionsTest extends TestCase
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
    public static function bulkActions(): array
    {
        $out = [];
        $names = [
            'BulkStartAction', 'BulkStopAction', 'BulkDrainStopAction', 'BulkRollingRestartAction', 'BulkDeleteAction',
        ];
        foreach ($names as $n) {
            $out[$n] = ['RunnerDeck\\Http\\Action\\Runners\\' . $n];
        }
        return $out;
    }

    /** @param class-string $class */
    #[DataProvider('bulkActions')]
    public function testNothingSelectedIsRejected(string $class): void
    {
        $res = $this->call($class, ['runners' => ' , ']);

        $this->assertSame(422, $res['status']);
        $this->assertSame('no runners selected', $res['data']['message']);
    }

    /** @param class-string $class */
    #[DataProvider('bulkActions')]
    public function testAnUnknownRunnerInTheSelectionIsRejected(string $class): void
    {
        $this->slot('runner-1');

        $res = $this->call($class, ['runners' => 'runner-1,runner-7']);

        $this->assertSame(404, $res['status']);
        $this->assertSame('unknown runner(s): runner-7', $res['data']['message']);
    }

    public function testBulkStopRefusesABusyRunner(): void
    {
        $this->slot('runner-1');
        $this->slot('runner-2');
        $this->ghRunners([['id' => 1, 'name' => 'acme-1', 'busy' => true], ['id' => 2, 'name' => 'acme-2']]);

        $res = $this->call(A\BulkStopAction::class, ['runners' => 'runner-1,runner-2']);

        $this->assertSame(409, $res['status']);
        $this->assertSame(['acme-1'], $res['data']['busy_runners']);
    }

    public function testBulkStopWithForceStopsTheSelection(): void
    {
        $this->slot('runner-1');
        $this->slot('runner-2');

        $res = $this->call(A\BulkStopAction::class, ['runners' => 'runner-1, runner-2', 'force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $this->assertStringContainsString('runner-2 not running', $res['data']['message']);
    }

    public function testBulkStartReportsEachFailure(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\BulkStartAction::class, ['runners' => 'runner-1']);

        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('failed to start runner-1', $res['data']['message']);
    }

    public function testBulkDeleteRefusesWhenGithubCannotConfirmIdle(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\BulkDeleteAction::class, ['runners' => 'runner-1']);

        $this->assertSame(409, $res['status']);
        $this->assertTrue($res['data']['busy_unknown']);
        $this->assertDirectoryExists($this->httpRoot . '/pool/runner-1');
    }

    public function testBulkDeleteWithForceRemovesTheSlots(): void
    {
        $this->slot('runner-1');
        $this->slot('runner-2');
        $this->ghRunners([['id' => 1, 'name' => 'acme-1'], ['id' => 2, 'name' => 'acme-2']]);

        $res = $this->call(A\BulkDeleteAction::class, ['runners' => 'runner-1,runner-2', 'force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertDirectoryDoesNotExist($this->httpRoot . '/pool/runner-1');
        $this->assertDirectoryDoesNotExist($this->httpRoot . '/pool/runner-2');
    }

    public function testBulkDrainStopRefusesWhenGithubCannotConfirmIdle(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\BulkDrainStopAction::class, ['runners' => 'runner-1', 'timeout' => '1']);

        $this->assertSame(409, $res['status']);
        $this->assertTrue($res['data']['busy_unknown']);
    }

    public function testBulkDrainStopWithForceStopsAtOnce(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\BulkDrainStopAction::class, ['runners' => 'runner-1', 'force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('runner-1 not running', $res['data']['message']);
    }

    public function testBulkRollingRestartWithForceTriesToStartAgain(): void
    {
        $this->slot('runner-1');

        $res = $this->call(A\BulkRollingRestartAction::class, [
            'runners' => 'runner-1', 'force' => '1', 'timeout' => '1',
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['data']['ok']);
    }

    public function testRollingUpgradeWithNoConfiguredSlotsHasNothingToDo(): void
    {
        $this->slot('runner-1', false);

        $res = $this->call(A\RollingUpgradeAction::class, ['force' => '1', 'timeout' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('No configured runners', $res['data']['message']);
    }

    public function testRemoveOrphanNeedsAName(): void
    {
        $res = $this->call(A\RemoveOrphanAction::class, ['name' => '']);

        $this->assertSame(422, $res['status']);
    }

    public function testRemoveOrphanRefusesASlotThatStillExistsLocally(): void
    {
        $this->slot('runner-1');
        AgentRoster::remember('acme-1');

        $res = $this->call(A\RemoveOrphanAction::class, ['name' => 'acme-1']);

        $this->assertSame(409, $res['status']);
        $this->assertStringContainsString('delete the runner instead', $res['data']['message']);
    }

    public function testRemoveOrphanRefusesANameThisHostNeverRegistered(): void
    {
        $res = $this->call(A\RemoveOrphanAction::class, ['name' => 'someone-elses']);

        $this->assertSame(404, $res['status']);
        $this->assertStringContainsString('not a runner this host registered', $res['data']['message']);
    }

    public function testRemoveOrphanReportsAGithubListFailure(): void
    {
        AgentRoster::remember('ghost');

        $res = $this->call(A\RemoveOrphanAction::class, ['name' => 'ghost']);

        $this->assertSame(502, $res['status']);
        $this->assertFalse($res['data']['ok']);
    }

    public function testRemoveOrphanForgetsARunnerGithubAlreadyDropped(): void
    {
        AgentRoster::remember('ghost');
        $this->ghRunners([]);

        $res = $this->call(A\RemoveOrphanAction::class, ['name' => 'ghost']);

        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('already gone from GitHub', $res['data']['message']);
        $this->assertNotContains('ghost', AgentRoster::names());
    }

    public function testRemoveOrphanRefusesABusyRunnerWithoutForce(): void
    {
        AgentRoster::remember('ghost');
        $this->ghRunners([['id' => 9, 'name' => 'ghost', 'busy' => true]]);

        $res = $this->call(A\RemoveOrphanAction::class, ['name' => 'ghost']);

        $this->assertSame(409, $res['status']);
        $this->assertTrue($res['data']['busy']);
        $this->assertContains('ghost', AgentRoster::names());
    }

    public function testRemoveOrphanDeletesItFromGithubAndTheRoster(): void
    {
        AgentRoster::remember('ghost');
        $this->ghRunners([['id' => 9, 'name' => 'ghost', 'busy' => true]]);

        $res = $this->call(A\RemoveOrphanAction::class, ['name' => 'ghost', 'force' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('ghost removed from GitHub', $res['data']['message']);
        $this->assertNotContains('ghost', AgentRoster::names());
    }

    public function testRemoveOrphanReportsAFailedGithubDelete(): void
    {
        AgentRoster::remember('ghost');
        $list = json_encode(['runners' => [
            ['id' => 9, 'name' => 'ghost', 'status' => 'offline', 'busy' => false, 'labels' => []],
        ]]);
        $this->fakeGh(static fn (array $cmd): array => in_array('DELETE', $cmd, true)
            ? ['code' => 1, 'stdout' => '', 'stderr' => 'forbidden']
            : ['code' => 0, 'stdout' => (string) $list, 'stderr' => '']);

        $res = $this->call(A\RemoveOrphanAction::class, ['name' => 'ghost']);

        $this->assertSame(502, $res['status']);
        $this->assertStringContainsString('forbidden', $res['data']['message']);
        $this->assertContains('ghost', AgentRoster::names());
    }
}
