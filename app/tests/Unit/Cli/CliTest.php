<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\Cli;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\RunnerPool;

final class CliTest extends TestCase
{
    private string $pool;

    protected function setUp(): void
    {
        $this->pool = sys_get_temp_dir() . '/runnerdeck-cli-test-' . uniqid();
        mkdir($this->pool . '/runner-1', 0755, true);
        mkdir($this->pool . '/runner-2', 0755, true);
        file_put_contents($this->pool . '/runner-1/runner.log', "one\ntwo\nthree\n");
        mkdir($this->pool . '-storage', 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->pool . '-storage/settings.json');
        putenv('RUNNERDECK_POOL_DIR=' . $this->pool);
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        RunnerPool::fakeLiveListeners([]);
        RunnerPool::fakeCheckProcess(fn () => [false, null]);
    }

    protected function tearDown(): void
    {
        RunnerPool::fakeLiveListeners(null);
        RunnerPool::fakeCheckProcess(null);
        ProcessControl::fakeKill(null);
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_SETTINGS_FILE');
    }

    /** @param list<string> $argv @return array{0: int, 1: string, 2: string} */
    private function cli(array $argv): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new Cli($out, $err))->run($argv);
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testNoArgumentsAndHelpPrintUsage(): void
    {
        foreach ([[], ['help'], ['--help'], ['list', '-h']] as $argv) {
            [$code, $out] = $this->cli($argv);
            $this->assertSame(0, $code);
            $this->assertStringContainsString('Usage: runnerdeck <command>', $out);
        }
    }

    public function testVersionMatchesVersionFile(): void
    {
        [$code, $out] = $this->cli(['version']);
        $this->assertSame(0, $code);
        $version = trim((string) file_get_contents(dirname(__DIR__, 4) . '/VERSION'));
        $this->assertSame("RunnerDeck {$version}\n", $out);
    }

    public function testUnknownCommandFailsWithUsageOnStderr(): void
    {
        [$code, $out, $err] = $this->cli(['bogus']);
        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('unknown command: bogus', $err);
    }

    public function testListShowsEachRunnerAsATable(): void
    {
        [$code, $out] = $this->cli(['list']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('RUNNER', $out);
        $this->assertStringContainsString('runner-1', $out);
        $this->assertStringContainsString('runner-2', $out);
    }

    public function testListJsonOmitsLogTails(): void
    {
        [$code, $out] = $this->cli(['ls', '--json']);
        $data = json_decode($out, true);
        $this->assertSame(0, $code);
        $this->assertTrue($data['ok']);
        $this->assertSame(['runner-1', 'runner-2'], array_column($data['runners'], 'id'));
        $this->assertArrayNotHasKey('log_tail', $data['runners'][0]);
        $this->assertNull($data['runners'][0]['disk_kb']);
    }

    public function testListLabelKeepsSlotsThatHaveEveryExtraLabel(): void
    {
        \RunnerDeck\Pool\SlotFlags::setExtraLabels($this->pool . '/runner-1', ['gpu']);
        \RunnerDeck\Pool\SlotFlags::setExtraLabels($this->pool . '/runner-2', ['gpu', 'large']);
        putenv('RUNNERDECK_LABEL=gpu');
        putenv('RUNNERDECK_EXTRA_LABELS=linux');

        [$code, $out] = $this->cli(['list', '--label', 'gpu']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1', $out);
        $this->assertStringContainsString('runner-2', $out);

        [$code, $json] = $this->cli(['list', '--label', 'gpu', '--label', 'large', '--json']);
        $data = json_decode($json, true);
        $this->assertSame(0, $code);
        $this->assertSame(['runner-2'], array_column($data['runners'], 'id'));

        [$code, $empty] = $this->cli(['list', '--label', 'linux', '--json']);
        $data = json_decode($empty, true);
        $this->assertSame(0, $code);
        $this->assertSame([], $data['runners']);
        $this->assertStringNotContainsString('runnerdeck add', $empty);

        [$code, , $err] = $this->cli(['status', '--label', 'bad label!']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('labels must be', $err);

        [, $labels] = $this->cli(['__complete', 'labels']);
        $this->assertSame("gpu\nlarge\n", $labels);

        putenv('RUNNERDECK_LABEL');
        putenv('RUNNERDECK_EXTRA_LABELS');
    }

    public function testListWithNoRunnersPointsAtAdd(): void
    {
        putenv('RUNNERDECK_POOL_DIR=' . $this->pool . '/empty');
        [$code, $out] = $this->cli(['list']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runnerdeck add', $out);
    }

    public function testStopNeedsARunnerOrAll(): void
    {
        [$code, , $err] = $this->cli(['stop']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('name at least one runner', $err);
    }

    public function testUnknownRunnerIsRejectedBeforeAnythingRuns(): void
    {
        [$code, , $err] = $this->cli(['stop', 'runner-1', 'ghost', '--force']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown runner(s): ghost', $err);
    }

    public function testForcedStopOfIdleRunnersSucceeds(): void
    {
        [$code, $out] = $this->cli(['stop', '--all', '--force']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 not running', $out);
        $this->assertStringContainsString('runner-2 not running', $out);
    }

    public function testStopJsonReportsFailureInJson(): void
    {
        [$code, $out] = $this->cli(['stop', 'ghost', '--json']);
        $data = json_decode($out, true);
        $this->assertSame(1, $code);
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('ghost', $data['message']);
    }

    public function testAddRejectsBadLabels(): void
    {
        [$code, , $err] = $this->cli(['add', '--label', 'bad label!']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('labels must be', $err);
    }

    public function testLogsPrintsTheTail(): void
    {
        [$code, $out] = $this->cli(['logs', 'runner-1', '-n', '2']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('three', $out);
        $this->assertStringContainsString('two', $out);
        $this->assertStringNotContainsString('one', $out);
    }

    public function testLogsRequiresAKnownRunner(): void
    {
        [$code, , $err] = $this->cli(['logs']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('usage: runnerdeck logs', $err);
        [$code, , $err] = $this->cli(['logs', 'ghost']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown runner: ghost', $err);
    }

    public function testLogsJsonReturnsTheTailAndRejectsFollow(): void
    {
        [$code, $out] = $this->cli(['logs', 'runner-1', '--json', '-n', '2']);
        $data = json_decode($out, true);
        $this->assertSame(0, $code);
        $this->assertTrue($data['ok']);
        $this->assertSame('runner-1', $data['runner']);
        $this->assertSame(['two', 'three'], $data['lines']);

        file_put_contents($this->pool . '/runner-2/runner.log', '');
        [$code, $empty] = $this->cli(['logs', 'runner-2', '--json']);
        $this->assertSame(0, $code);
        $this->assertSame([], json_decode($empty, true)['lines']);

        [$code, $out] = $this->cli(['logs', 'runner-1', '--json', '--follow']);
        $data = json_decode($out, true);
        $this->assertSame(1, $code);
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('does not combine with --json', $data['message']);

        [$code, $out] = $this->cli(['logs', 'ghost', '--json']);
        $data = json_decode($out, true);
        $this->assertSame(1, $code);
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('unknown runner: ghost', $data['message']);
    }

    public function testListAndStatusAppendExtraLabelsInStoredOrder(): void
    {
        \RunnerDeck\Pool\SlotFlags::setExtraLabels($this->pool . '/runner-1', ['gpu', 'large']);
        \RunnerDeck\Pool\SlotFlags::setEphemeral($this->pool . '/runner-2', true);
        [$code, $out] = $this->cli(['list']);
        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/runner-1\s.*\[gpu,large\]/', $out);
        $this->assertMatchesRegularExpression('/runner-2\s.*\(ephemeral\)/', $out);
        $this->assertDoesNotMatchRegularExpression('/runner-2\s.*\[/', $out);

        \RunnerDeck\System\Shell::fake(fn (): array => [
            'code' => 0,
            'stdout' => json_encode(['runners' => []]),
            'stderr' => '',
        ]);
        try {
            [, $status] = $this->cli(['status']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertMatchesRegularExpression('/runner-1\s.*\[gpu,large\]/', $status);

        $json = json_decode($this->cli(['list', '--json'])[1], true);
        $this->assertSame(['gpu', 'large'], $json['runners'][0]['extra_labels']);
        $this->assertStringNotContainsString('[gpu,large]', $json['runners'][0]['agent_name']);
    }

    public function testLogsFollowPrintsOnlyNewLines(): void
    {
        $out = fopen('php://memory', 'w+');
        $cli = new Cli($out, fopen('php://memory', 'w+'));
        $polls = 0;
        $log = $this->pool . '/runner-1/runner.log';
        $cli->followWhile(function () use (&$polls, $log): bool {
            if ($polls++ === 0) {
                file_put_contents($log, "four\n", FILE_APPEND);
            }
            return $polls < 3;
        });
        $this->assertSame(0, $cli->run(['logs', 'runner-1', '-f', '-n', '10']));
        rewind($out);
        $text = (string) stream_get_contents($out);
        $this->assertSame(1, substr_count($text, "three\n"));
        $this->assertSame(1, substr_count($text, "four\n"));
    }

    public function testVersionFlagsWorkLikeTheCommand(): void
    {
        foreach ([['-v'], ['--version'], ['version']] as $argv) {
            [$code, $out] = $this->cli($argv);
            $this->assertSame(0, $code);
            $this->assertStringStartsWith('RunnerDeck ', $out);
        }
    }

    public function testStatusAddsWhatGithubReports(): void
    {
        $name = RunnerPool::agentNameFor('runner-1');
        \RunnerDeck\System\Shell::fake(fn (array $cmd): array => [
            'code' => 0,
            'stdout' => json_encode(['runners' => [
                ['name' => $name, 'status' => 'online', 'busy' => true, 'labels' => [], 'os' => 'Linux', 'id' => 1],
            ]]),
            'stderr' => '',
        ]);
        try {
            [$code, $out] = $this->cli(['status']);
            [, $json] = $this->cli(['status', '--json']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('GITHUB', $out);
        $this->assertMatchesRegularExpression('/runner-1\s.*busy/', $out);
        $this->assertMatchesRegularExpression('/runner-2\s.*unregistered/', $out);
        $this->assertSame('busy', json_decode($json, true)['runners'][0]['github']);
    }

    public function testStatusShowsAQuestionMarkWhenGithubIsUnreachable(): void
    {
        \RunnerDeck\System\Shell::fake(fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => 'off']);
        try {
            [$code, $out] = $this->cli(['status']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/runner-1\s.*\?/', $out);
    }

    public function testAnUnconfiguredRunnerShowsAsNew(): void
    {
        [, $out] = $this->cli(['list']);
        $this->assertMatchesRegularExpression('/runner-1\s+new\s/', $out);
    }

    public function testFlagsMayComeBeforeOrAfterTheRunnerNames(): void
    {
        $orders = [
            ['stop', '--force', 'runner-1'],
            ['stop', 'runner-1', '--force'],
            ['stop', '--force=1', 'runner-1'],
        ];
        foreach ($orders as $argv) {
            [$code, $out] = $this->cli($argv);
            $this->assertSame(0, $code);
            $this->assertStringContainsString('runner-1 not running', $out);
        }
    }

    public function testStopMarksTheRunnerAsDeliberatelyStopped(): void
    {
        $this->cli(['stop', 'runner-1', '--force']);
        $this->assertTrue(\RunnerDeck\Lifecycle\CrashState::export()['runner-1']['justStopped'] ?? false);
    }

    public function testStopRefusesWhenGithubCannotConfirmTheRunnerIsIdle(): void
    {
        \RunnerDeck\System\Shell::fake(
            fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => 'no network']
        );
        try {
            [$code, , $err] = $this->cli(['stop', 'runner-1']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Pass --force', $err);
    }

    public function testStopJsonCarriesTheBusyFlagWhenAJobIsRunning(): void
    {
        $name = RunnerPool::agentNameFor('runner-1');
        \RunnerDeck\System\Shell::fake(function (array $cmd) use ($name): array {
            $runners = ['runners' => [['name' => $name, 'status' => 'online', 'busy' => true,
                'labels' => [], 'os' => 'Linux', 'id' => 1]]];
            return ['code' => 0, 'stdout' => json_encode($runners), 'stderr' => ''];
        });
        try {
            [$code, $out] = $this->cli(['stop', 'runner-1', '--json']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $data = json_decode($out, true);
        $this->assertSame(1, $code);
        $this->assertTrue($data['busy'] ?? false);
    }

    public function testStopKillsARunningRunner(): void
    {
        file_put_contents($this->pool . '/runner-1/runner.pid', '4242');
        RunnerPool::fakeCheckProcess(fn () => [true, 4242]);
        $alive = true;
        ProcessControl::fakeAlive(function () use (&$alive): bool {
            return $alive;
        });
        ProcessControl::fakeTermWait(0);
        $signals = [];
        ProcessControl::fakeKill(function (int $pid, int $signal) use (&$alive, &$signals): bool {
            $signals[] = [$pid, $signal];
            $alive = false;
            return true;
        });
        try {
            [$code, $out] = $this->cli(['stop', 'runner-1', '--force']);
        } finally {
            ProcessControl::fakeAlive(null);
            ProcessControl::fakeTermWait(null);
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 stopped (pid 4242)', $out);
        $this->assertSame(4242, $signals[0][0]);
    }

    public function testStartReportsARunnerThatIsAlreadyRunning(): void
    {
        RunnerPool::fakeCheckProcess(fn () => [true, 777]);
        RunnerPool::fakeLiveListeners([]);
        [$code, $out] = $this->cli(['start', 'runner-1']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 already running (pid 777)', $out);
    }

    public function testStartIsRefusedInMaintenanceMode(): void
    {
        $this->useMaintenance(true);
        [$code, , $err] = $this->cli(['start', 'runner-1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('maintenance', strtolower($err));
    }

    public function testStartAllNamesEveryRunner(): void
    {
        RunnerPool::fakeCheckProcess(fn () => [true, 5]);
        [$code, $out] = $this->cli(['start', '--all']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 already running', $out);
        $this->assertStringContainsString('runner-2 already running', $out);
    }

    public function testAddIsRefusedWhenTheDiskIsFull(): void
    {
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [1024, 100 * 1024 * 1024 * 1024]);
        try {
            [$code, , $err] = $this->cli(['add', '--name', 'ci-box', '--ephemeral', '--label', 'gpu,fast']);
        } finally {
            \RunnerDeck\Host\HostPressure::fakeDisk(null);
        }
        $this->assertSame(1, $code);
        $this->assertNotSame('', trim($err));
    }

    public function testAddJsonReportsTheRefusalAsJson(): void
    {
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [1024, 100 * 1024 * 1024 * 1024]);
        try {
            [$code, $out] = $this->cli(['add', '--json']);
        } finally {
            \RunnerDeck\Host\HostPressure::fakeDisk(null);
        }
        $this->assertSame(1, $code);
        $this->assertFalse(json_decode($out, true)['ok']);
    }

    public function testRestartOfAnUnknownRunnerFailsBeforeTouchingAnything(): void
    {
        [$code, , $err] = $this->cli(['restart', 'ghost', '--force']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown runner(s): ghost', $err);
    }

    public function testRestartAllSelectsEveryRunner(): void
    {
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'offline']);
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [50 * 1024 * 1024 * 1024, 100 * 1024 * 1024 * 1024]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 8 * 1024 * 1024);
        try {
            [$code, $out, $err] = $this->cli(['restart', '--all', '--force']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
            \RunnerDeck\Host\HostPressure::fakeDisk(null);
            \RunnerDeck\Host\HostPressure::fakeRam(null);
        }
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('name at least one runner', $err);
        $this->assertSame(2, substr_count($out . $err, 'failed to list runner downloads'));
    }

    public function testResizeCountIncludesTheBaseAndStopsTheRest(): void
    {
        mkdir($this->pool . '/runner-base', 0755, true);
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'offline']);
        \RunnerDeck\Host\HostPressure::fakeDisk(fn () => [50 * 1024 * 1024 * 1024, 100 * 1024 * 1024 * 1024]);
        \RunnerDeck\Host\HostPressure::fakeRam(fn () => 8 * 1024 * 1024);
        try {
            [$code, $out] = $this->cli(['resize', '2']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
            \RunnerDeck\Host\HostPressure::fakeDisk(null);
            \RunnerDeck\Host\HostPressure::fakeRam(null);
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-2 not running', $out);
        $this->assertSame(2, substr_count($out, 'failed to list runner downloads'));
    }

    public function testRestartWithNoRunnerAsksForOne(): void
    {
        [$code, , $err] = $this->cli(['restart']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('name at least one runner', $err);
    }

    public function testRestartStopsThenReportsWhyItCannotStart(): void
    {
        $this->useMaintenance(true);
        [$code] = $this->cli(['restart', 'runner-1', '--force']);
        $this->assertSame(1, $code);
    }

    public function testDeleteRemovesAnUnconfiguredRunner(): void
    {
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'rm') {
                exec('rm -rf ' . escapeshellarg($cmd[2]));
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        try {
            [$code, $out] = $this->cli(['delete', 'runner-2', '--force']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-2 deleted', $out);
        $this->assertDirectoryDoesNotExist($this->pool . '/runner-2');
    }

    public function testRmIsAnAliasForDelete(): void
    {
        [$code, , $err] = $this->cli(['rm', 'ghost']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown runner(s): ghost', $err);
    }

    public function testDeleteNeedsARunnerName(): void
    {
        [$code, , $err] = $this->cli(['delete']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('name at least one runner', $err);
    }

    public function testLogsLinesFlagAndDefaultCount(): void
    {
        [, $out] = $this->cli(['logs', 'runner-1', '--lines=1']);
        $this->assertSame("three\n", $out);
        [, $all] = $this->cli(['logs', 'runner-1']);
        $this->assertStringContainsString('one', $all);
    }

    public function testListJsonOnAnEmptyPoolIsStillValidJson(): void
    {
        putenv('RUNNERDECK_POOL_DIR=' . $this->pool . '/none');
        [$code, $out] = $this->cli(['list', '--json']);
        $this->assertSame(0, $code);
        $this->assertSame([], json_decode($out, true)['runners']);
    }

    public function testUpdateNeedsNoRunnersAndReportsCheckErrors(): void
    {
        \RunnerDeck\System\Shell::fake(
            fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in']
        );
        try {
            [$code, , $err] = $this->cli(['update', '--check']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not check for updates: not logged in', $err);
    }

    public function testUpgradeIsAnAliasForUpdate(): void
    {
        $this->fakeLatest('99.0.0');
        try {
            [$code, $out] = $this->cli(['upgrade', '--check']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('RunnerDeck 99.0.0 is available', $out);
    }

    private function fakeLatest(string $version): void
    {
        \RunnerDeck\System\Shell::fake(fn (array $cmd): array => [
            'code' => 0,
            'stdout' => json_encode(['tag_name' => 'v' . $version]),
            'stderr' => '',
        ]);
    }

    private function useMaintenance(bool $on): void
    {
        \RunnerDeck\Host\HostMode::setMaintenance($on);
    }

    public function testDurationsUseTheLargestUnit(): void
    {
        $this->assertSame('-', Cli::duration(null));
        $this->assertSame('42s', Cli::duration(42));
        $this->assertSame('5m', Cli::duration(300));
        $this->assertSame('2h3m', Cli::duration(2 * 3600 + 3 * 60 + 5));
        $this->assertSame('3d4h', Cli::duration(3 * 86400 + 4 * 3600));
    }

    public function testRestartAndDeleteRefuseWhenGithubCannotConfirmIdle(): void
    {
        \RunnerDeck\System\Shell::fake(
            fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => 'offline']
        );
        try {
            [$restart, , $restartErr] = $this->cli(['restart', 'runner-1']);
            [$delete, , $deleteErr] = $this->cli(['delete', 'runner-1']);
        } finally {
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertSame(1, $restart);
        $this->assertSame(1, $delete);
        $this->assertStringContainsString('Pass --force', $restartErr);
        $this->assertStringContainsString('Pass --force', $deleteErr);
        $this->assertDirectoryExists($this->pool . '/runner-1');
    }

    public function testUpdateRunsItsCommandsThroughTheInjectedRunner(): void
    {
        $this->fakeLatest('99.0.0');
        $ran = [];
        $out = fopen('php://memory', 'w+');
        $cli = new Cli($out, fopen('php://memory', 'w+'), fopen('php://memory', 'r'));
        $cli->runCommandsWith(function (array $cmd) use (&$ran): int {
            $ran[] = $cmd;
            return 0;
        });
        putenv('RUNNERDECK_INSTALL_METHOD=brew');
        try {
            $code = $cli->run(['update', '-y']);
        } finally {
            putenv('RUNNERDECK_INSTALL_METHOD');
            \RunnerDeck\System\Shell::fake(null);
        }
        $this->assertSame(0, $code);
        $this->assertSame([['brew', 'upgrade', 'anikethts/runnerdeck/runnerdeck']], $ran);
    }
}
