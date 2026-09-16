<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        \Shell::fake(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
    }
    public function testParseRunnerIdsSplitsAndDropsBlanks(): void
    {
        $this->assertSame(['runner-1', 'runner-2'], \Api::parseRunnerIds(' runner-1, runner-2 ,'));
        $this->assertSame([], \Api::parseRunnerIds(''));
        $this->assertSame([], \Api::parseRunnerIds(' , , '));
    }

    public function testForceReadsPostThenGet(): void
    {
        $_POST = [];
        $_GET = [];
        $this->assertFalse(\Api::force());

        $_GET['force'] = '1';
        $this->assertTrue(\Api::force());

        $_POST['force'] = '0';
        $this->assertFalse(\Api::force());

        $_POST['force'] = '1';
        $this->assertTrue(\Api::force());
    }

    public function testBusyChecksAreSkippedWhenForced(): void
    {
        $this->assertNull(\Api::checkNotBusy('any', true));
        $this->assertNull(\Api::checkNoneBusy([], true));
    }

    #[RunInSeparateProcess]
    public function testCheckNotBusyFailsClosedWhenGithubIsUnreachable(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        \Shell::fake(fn () => ['code' => 1, 'stdout' => '', 'stderr' => 'gh down']);

        $blocked = \Api::checkNotBusy('acme-1', false);

        $this->assertNotNull($blocked);
        $this->assertTrue($blocked['busy_unknown']);
        $this->assertStringContainsString('gh down', $blocked['message']);
    }

    #[RunInSeparateProcess]
    public function testCheckNoneBusyReportsBusyNames(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        \Shell::fake(fn () => [
            'code' => 0,
            'stdout' => json_encode([
                'runners' => [
                    ['id' => 1, 'name' => 'busy-one', 'status' => 'online', 'busy' => true, 'labels' => []],
                    ['id' => 2, 'name' => 'idle-one', 'status' => 'online', 'busy' => false, 'labels' => []],
                ],
            ]),
            'stderr' => '',
        ]);

        $blocked = \Api::checkNoneBusy([
            $this->runner('runner-1', 'busy-one'),
            $this->runner('runner-2', 'idle-one'),
        ], false);

        $this->assertNotNull($blocked);
        $this->assertTrue($blocked['busy']);
        $this->assertSame(['busy-one'], $blocked['busy_runners']);
    }

    #[RunInSeparateProcess]
    public function testCheckNoneBusyAllowsIdleSelection(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        \Shell::fake(fn () => [
            'code' => 0,
            'stdout' => json_encode([
                'runners' => [
                    ['id' => 2, 'name' => 'idle-one', 'status' => 'online', 'busy' => false, 'labels' => []],
                ],
            ]),
            'stderr' => '',
        ]);

        $this->assertNull(\Api::checkNoneBusy([$this->runner('runner-2', 'idle-one')], false));
    }

    #[RunInSeparateProcess]
    public function testCheckNotBusyWhenRunnerIsBusy(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        \Shell::fake(fn () => [
            'code' => 0,
            'stdout' => json_encode([
                'runners' => [
                    ['id' => 1, 'name' => 'busy-one', 'status' => 'online', 'busy' => true, 'labels' => []],
                ],
            ]),
            'stderr' => '',
        ]);

        $blocked = \Api::checkNotBusy('busy-one', false);

        $this->assertNotNull($blocked);
        $this->assertTrue($blocked['busy']);
        $this->assertStringContainsString('stop anyway', $blocked['message']);
    }

    private function runner(string $id, string $agentName): \RunnerInfo
    {
        return new \RunnerInfo(
            id: $id,
            dir: '/tmp/' . $id,
            configured: true,
            agentName: $agentName,
            localRunning: false,
            pid: null,
            logTail: [],
        );
    }
}
