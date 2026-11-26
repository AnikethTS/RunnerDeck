<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use RunnerDeck\Cli\Cli;
use RunnerDeck\Host\HostMode;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\System\Shell;
use Tests\Support\CliTestCase;

final class CliCommandsTest extends CliTestCase
{
    public function testHelpListsEveryCommandGroup(): void
    {
        [, $out] = $this->cli(['help']);
        foreach (
            ['rolling-restart', 'drain', 'resize', 'rename', 'force-idle', 'maintenance', 'setup', 'config',
            'auth', 'update', 'install', 'completion', '--watch', '--quiet', '--no-color', '--dry-run'] as $word
        ) {
            $this->assertStringContainsString($word, $out);
        }
    }

    public function testVersionShowsTheLogoOnATerminalAndPlainTextOtherwise(): void
    {
        [, $plain] = $this->cli(['-v']);
        $this->assertSame('RunnerDeck ' . \RunnerDeck\Configuration\Config::version() . "\n", $plain);

        foreach ([['--version', '--color'], ['version', '--color', '--no-color']] as $argv) {
            [$code, $out] = $this->cli($argv);
            $this->assertSame(0, $code);
            $this->assertStringContainsString('Welcome to RunnerDeck', $out);
            $this->assertStringContainsString('One dashboard for every self-hosted', $out);
            $this->assertStringContainsString('@@@', $out);
        }
        [, $coloured] = $this->cli(['-v', '--color']);
        $this->assertStringContainsString("\033[", $coloured);
        [, $uncoloured] = $this->cli(['-v', '--color', '--no-color']);
        $this->assertStringNotContainsString("\033[", $uncoloured);
    }

    public function testVersionStaysPlainForQuietJsonAndNoBanner(): void
    {
        foreach ([['-v', '--color', '-q'], ['-v', '--color', '--json']] as $argv) {
            [, $out] = $this->cli($argv);
            $this->assertSame(1, substr_count($out, "\n"), implode(' ', $argv));
            $this->assertStringStartsWith('RunnerDeck ', $out);
        }
        putenv('RUNNERDECK_NO_BANNER=1');
        try {
            [, $out] = $this->cli(['-v', '--color']);
        } finally {
            putenv('RUNNERDECK_NO_BANNER');
        }
        $this->assertStringStartsWith('RunnerDeck ', $out);
    }

    public function testRollingRestartNeedsRunners(): void
    {
        [$code, , $err] = $this->cli(['rolling-restart']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('name at least one runner', $err);
    }

    public function testRollingRestartSkipsRunnersThatAreNotSetUpYet(): void
    {
        $this->githubBusy();
        [$code] = $this->cli(['rolling-restart', 'runner-1', '--timeout', '5']);
        $this->assertSame(0, $code);
    }

    public function testRollingRestartRefusesWhenGithubCannotConfirmIdle(): void
    {
        file_put_contents($this->pool . '/runner-1/.runner', '{}');
        $this->githubDown();
        [$code, , $err] = $this->cli(['rolling-restart', 'runner-1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Pass --force', $err);
    }

    public function testDrainStopsIdleRunnersWhenGithubSaysIdle(): void
    {
        $this->githubBusy();
        [$code, $out] = $this->cli(['drain', '--all']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Waiting for running jobs to finish', $out);
        $this->assertStringContainsString('runner-1 not running', $out);
        $this->assertTrue(\RunnerDeck\Lifecycle\CrashState::export()['runner-2']['justStopped'] ?? false);
    }

    public function testDrainGivesUpAfterTheTimeoutWhileAJobRuns(): void
    {
        $this->githubBusy(RunnerPool::agentNameFor('runner-1'));
        $this->fastClock();
        [$code, , $err] = $this->cli(['drain', 'runner-1', '--timeout', '3']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Pass --force', $err);
    }

    public function testDrainForceSkipsWaiting(): void
    {
        $this->githubBusy(RunnerPool::agentNameFor('runner-1'));
        [$code] = $this->cli(['drain', 'runner-1', '--force']);
        $this->assertSame(0, $code);
    }

    public function testQuietHidesProgressAndSuccessButNotErrors(): void
    {
        $this->githubBusy();
        [$code, $out] = $this->cli(['drain', '--all', '-q']);
        $this->assertSame(0, $code);
        $this->assertSame('', $out);
        [$code, , $err] = $this->cli(['drain', 'ghost', '--quiet']);
        $this->assertSame(1, $code);
        $this->assertNotSame('', $err);
    }

    public function testMaintenanceStatusOnAndOff(): void
    {
        $this->githubBusy();
        [, $out] = $this->cli(['maintenance']);
        $this->assertStringContainsString('Maintenance is off', $out);

        [$code, $out] = $this->cli(['maintenance', 'on']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Maintenance on.', $out);
        $this->assertTrue(HostMode::isMaintenance());
        [, $out] = $this->cli(['maintenance', 'status']);
        $this->assertStringContainsString('Maintenance is on', $out);

        [$code, $out] = $this->cli(['maintenance', 'off']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Maintenance off.', $out);
        $this->assertFalse(HostMode::isMaintenance());
    }

    public function testMaintenanceOnIsRefusedWhileAJobRuns(): void
    {
        $this->githubBusy(RunnerPool::agentNameFor('runner-1'));
        $this->fastClock();
        [$code, , $err] = $this->cli(['maintenance', 'on', '--timeout', '1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Pass --force', $err);
    }

    public function testMaintenanceRejectsUnknownModes(): void
    {
        [$code, , $err] = $this->cli(['maintenance', 'sideways']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('usage: runnerdeck maintenance', $err);
    }

    public function testResizeValidatesTheCount(): void
    {
        foreach ([[], ['0'], ['31'], ['many']] as $args) {
            [$code, , $err] = $this->cli(['resize', ...$args]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('usage: runnerdeck resize', $err);
        }
    }

    public function testResizeIsRefusedInMaintenanceMode(): void
    {
        HostMode::setMaintenance(true);
        [$code, , $err] = $this->cli(['resize', '3']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('aintenance', $err);
    }

    public function testRenameNeedsARunnerAndAName(): void
    {
        [$code, , $err] = $this->cli(['rename', 'runner-1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('usage: runnerdeck rename', $err);
        [$code, , $err] = $this->cli(['rename', 'ghost', 'new-name']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown runner(s): ghost', $err);
    }

    public function testRenameIsRefusedWhileTheRunnerHasAJob(): void
    {
        $this->githubBusy(RunnerPool::agentNameFor('runner-1'));
        [$code, , $err] = $this->cli(['rename', 'runner-1', 'builder']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('currently running a job', $err);
    }

    public function testForceIdleRefusesARunnerStillRunningLocally(): void
    {
        RunnerPool::fakeCheckProcess(fn () => [true, 99]);
        [$code, , $err] = $this->cli(['force-idle', 'runner-1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('still running locally', $err);
    }

    public function testForceIdleNeedsAKnownRunner(): void
    {
        [$code, , $err] = $this->cli(['force-idle', 'ghost']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown runner(s): ghost', $err);
    }

    public function testColourIsOffForPipesAndOnWhenForced(): void
    {
        [, $plain] = $this->cli(['list']);
        $this->assertStringNotContainsString("\033[", $plain);
        [, $colour] = $this->cli(['list', '--color']);
        $this->assertStringContainsString("\033[36mnew", $colour);
        [, $off] = $this->cli(['list', '--color', '--no-color']);
        $this->assertStringNotContainsString("\033[", $off);
    }

    public function testNoColorEnvironmentVariableWins(): void
    {
        putenv('NO_COLOR=1');
        [, $out] = $this->cli(['list']);
        $this->assertStringNotContainsString("\033[", $out);
    }

    public function testWatchRepaintsUntilStopped(): void
    {
        $out = fopen('php://memory', 'w+');
        $cli = new Cli($out, fopen('php://memory', 'w+'));
        $polls = 0;
        $cli->followWhile(function () use (&$polls): bool {
            return ++$polls <= 3;
        });
        $this->assertSame(0, $cli->run(['list', '--watch', '--interval', '1']));
        rewind($out);
        $text = (string) stream_get_contents($out);
        $this->assertSame(3, substr_count($text, 'runner-1'));
        $this->assertSame(0, substr_count($text, "\033[H"));
    }

    public function testWatchClearsTheScreenWhenColourIsOn(): void
    {
        $out = fopen('php://memory', 'w+');
        $cli = new Cli($out, fopen('php://memory', 'w+'));
        $polls = 0;
        $cli->followWhile(function () use (&$polls): bool {
            return ++$polls <= 2;
        });
        $cli->run(['list', '--watch', '--color']);
        rewind($out);
        $this->assertSame(2, substr_count((string) stream_get_contents($out), "\033[H\033[2J"));
    }

    public function testLogsNeedsARunnerAndNamesAnUnknownId(): void
    {
        [$code, , $err] = $this->cli(['logs']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('usage: runnerdeck logs', $err);

        [$code, , $err] = $this->cli(['logs', 'runner-9']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('runner-9', $err);
    }

    public function testLogsNPrintsOnlyTheRequestedTail(): void
    {
        [$code, $out] = $this->cli(['logs', 'runner-1', '-n', '2']);
        $this->assertSame(0, $code);
        $this->assertSame("two\nthree\n", $out);
    }

    public function testLogsClampsTheLineCount(): void
    {
        [$code, $one] = $this->cli(['logs', 'runner-1', '-n', '0']);
        $this->assertSame(0, $code);
        $this->assertSame("three\n", $one);

        $lines = [];
        for ($i = 1; $i <= 2001; $i++) {
            $lines[] = 'line-' . $i;
        }
        file_put_contents($this->pool . '/runner-1/runner.log', implode("\n", $lines) . "\n");
        [$code, $capped] = $this->cli(['logs', 'runner-1', '-n', '9000']);
        $this->assertSame(0, $code);
        $printed = array_values(array_filter(explode("\n", $capped), static fn (string $line): bool => $line !== ''));
        $this->assertCount(2000, $printed);
        $this->assertSame('line-2', $printed[0]);
        $this->assertSame('line-2001', $printed[1999]);
    }

    public function testLogsOfAnEmptyFilePrintsNothing(): void
    {
        file_put_contents($this->pool . '/runner-2/runner.log', '');
        [$code, $out, $err] = $this->cli(['logs', 'runner-2']);
        $this->assertSame(0, $code);
        $this->assertSame('', $out);
        $this->assertSame('', $err);
    }

    public function testLogsFollowPrintsALineWrittenAfterTheFirstRead(): void
    {
        $out = fopen('php://memory', 'w+');
        $this->assertIsResource($out);
        $cli = new Cli($out, fopen('php://memory', 'w+'));
        $polls = 0;
        $log = $this->pool . '/runner-1/runner.log';
        $cli->followWhile(function () use (&$polls, $log): bool {
            if ($polls === 0) {
                file_put_contents($log, "four\n", FILE_APPEND);
            }
            return ++$polls < 2;
        });
        $started = hrtime(true);
        $this->assertSame(0, $cli->run(['logs', 'runner-1', '-f', '-n', '10']));
        $this->assertLessThan(1_000_000_000, hrtime(true) - $started);
        rewind($out);
        $text = (string) stream_get_contents($out);
        $this->assertStringContainsString("four\n", $text);
        $this->assertSame(1, substr_count($text, "four\n"));
    }

    public function testDryRunReportsTheActionAndDoesNotPerformIt(): void
    {
        $killed = [];
        ProcessControl::fakeKill(static function (int $pid, int $signal) use (&$killed): bool {
            $killed[] = $pid;
            return $signal >= 0;
        });
        RunnerPool::fakeCheckProcess(static fn (): array => [true, 4242]);
        $this->githubBusy();

        [$code, $out] = $this->cli(['stop', 'runner-1', '--dry-run']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 would stop', $out);
        $this->assertSame([], $killed);
        $this->assertDirectoryExists($this->pool . '/runner-1');

        [$code, $json] = $this->cli(['restart', 'runner-1', '--dry-run', '--json']);
        $data = json_decode($json, true);
        $this->assertSame(0, $code);
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['dry_run']);
        $this->assertSame('restart', $data['actions'][0]['action']);
        $this->assertSame([], $killed);

        [$code, $out] = $this->cli(['delete', 'runner-1', '--dry-run']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 would delete', $out);
        $this->assertDirectoryExists($this->pool . '/runner-1');

        [$code, $out] = $this->cli(['drain', '--all', '--dry-run']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 would drain', $out);
        $this->assertStringContainsString('runner-2 would drain', $out);
        $this->assertStringNotContainsString('Waiting for running jobs', $out);
    }

    public function testDryRunRefusesABusyRunnerAndForceStillDoesNotAct(): void
    {
        $killed = [];
        ProcessControl::fakeKill(static function (int $pid, int $signal) use (&$killed): bool {
            $killed[] = $pid;
            return $signal >= 0;
        });
        RunnerPool::fakeCheckProcess(static fn (): array => [true, 4242]);
        $this->githubBusy(RunnerPool::agentNameFor('runner-1'));

        [$code, $out] = $this->cli(['stop', 'runner-1', '--dry-run']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('would refuse', $out);
        $this->assertStringContainsString('currently running a job', $out);
        $this->assertSame([], $killed);

        [$code, $out] = $this->cli(['stop', 'runner-1', '--force', '--dry-run']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 would stop', $out);
        $this->assertStringNotContainsString('would refuse', $out);
        $this->assertSame([], $killed);

        [$code, $json] = $this->cli(['stop', 'runner-1', '--dry-run', '--json']);
        $data = json_decode($json, true);
        $this->assertSame(0, $code);
        $this->assertTrue($data['dry_run']);
        $this->assertSame('refuse', $data['actions'][0]['action']);
        $this->assertArrayHasKey('reason', $data['actions'][0]);
    }

    public function testDryRunRefusesWhenGithubCannotBeReached(): void
    {
        $this->githubDown();
        [$code, $out] = $this->cli(['drain', 'runner-1', '--dry-run']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('runner-1 would refuse', $out);
        $this->assertStringContainsString('could not verify job status', $out);
    }

    public function testWatchDoesNotCombineWithJson(): void
    {
        [$code, $out] = $this->cli(['list', '--watch', '--json']);
        $this->assertSame(1, $code);
        $this->assertFalse(json_decode($out, true)['ok']);
    }

    public function testCompleteListsRunnerIdsAndSettingNames(): void
    {
        [, $runners] = $this->cli(['__complete', 'runners']);
        $this->assertSame("runner-1\nrunner-2\n", $runners);
        [, $settings] = $this->cli(['__complete', 'settings']);
        $this->assertStringContainsString("drain-timeout\n", $settings);
        $this->assertStringNotContainsString('totp', $settings);
    }

    public function testCompletionScriptsAndErrors(): void
    {
        foreach (['bash', 'zsh', 'fish', 'powershell'] as $shell) {
            [$code, $out] = $this->cli(['completion', $shell]);
            $this->assertSame(0, $code);
            $this->assertStringContainsString('runnerdeck', $out);
        }
        [$code, , $err] = $this->cli(['completion', 'tcsh']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('bash|zsh|fish|powershell', $err);
    }

    public function testInstallAndUninstallLinkTheCheckoutScript(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('symlinks');
        }
        $dir = sys_get_temp_dir() . '/runnerdeck-bin-' . uniqid();
        [$code, $out] = $this->cli(['install', '--dir', $dir, '--no-completion']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Installed:', $out);
        $this->assertStringContainsString('is not on your PATH', $out);
        $this->assertTrue(is_link($dir . '/runnerdeck'));

        [$code, $out] = $this->cli(['uninstall', '--dir', $dir], "n\n");
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Are you sure you want to uninstall RunnerDeck :( ?', $out);
        $this->assertStringContainsString('https://github.com/AnikethTS/RunnerDeck/issues', $out);
        $this->assertStringContainsString('Not uninstalled :) .', $out);
        $this->assertTrue(is_link($dir . '/runnerdeck'));

        [$code, $out] = $this->cli(['uninstall', '--dir', $dir], "y\n");
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Removed', $out);
        $this->assertFileDoesNotExist($dir . '/runnerdeck');

        [$code, $out] = $this->cli(['install', '--dir', $dir, '--no-completion']);
        $this->assertSame(0, $code);
        [$code, $out] = $this->cli(['uninstall', '--yes', '--dir', $dir]);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('Are you sure', $out);
        $this->assertStringContainsString('Removed', $out);
        $this->assertFileDoesNotExist($dir . '/runnerdeck');

        [$code, $out, $err] = $this->cli(['uninstall', '--json', '--dir', $dir]);
        $this->assertSame(1, $code);
        $this->assertSame('', $err);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('pass --yes to uninstall without a prompt', $decoded['message']);
        rmdir($dir);
    }

    public function testSetupWithFlagsSavesAndReportsLogin(): void
    {
        Shell::fake(fn (array $cmd): array => ['code' => 0, 'stdout' => 'ok', 'stderr' => '']);
        [$code, $out] = $this->cli(['setup', '--repo', 'acme/widgets']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Scope: repo (acme/widgets)', $out);
        $this->assertStringContainsString('signed in', $out);
        $this->assertSame('repo', \RunnerDeck\Configuration\Settings::load()['RUNNERDECK_SCOPE']);
    }

    public function testSetupInfersTheScopeFromOrgOrRepo(): void
    {
        Shell::fake(fn (array $cmd): array => ['code' => 0, 'stdout' => '', 'stderr' => '']);
        $this->assertSame(0, $this->cli(['setup', '--org', 'acme'])[0]);
        $this->assertSame('org', \RunnerDeck\Configuration\Settings::load()['RUNNERDECK_SCOPE']);
        $this->assertSame(0, $this->cli(['setup', '--repo', 'acme/widgets'])[0]);
        $this->assertSame('repo', \RunnerDeck\Configuration\Settings::load()['RUNNERDECK_SCOPE']);
    }

    public function testSetupPromptsForWhatItIsNotGiven(): void
    {
        Shell::fake(fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in']);
        [$code, $out] = $this->cli(['setup'], "org\nacme\n");
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Organization name:', $out);
        $this->assertStringContainsString('runnerdeck auth login', $out);
        $this->assertSame('acme', \RunnerDeck\Configuration\Settings::load()['RUNNERDECK_ORG']);
    }

    public function testSetupRejectsBadInput(): void
    {
        [$code, , $err] = $this->cli(['setup', '--scope', 'galaxy']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("scope must be 'org' or 'repo'", $err);
        [$code, , $err] = $this->cli(['setup', '--repo', 'no-slash']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("owner/repo", $err);
    }

    public function testConfigListGetSetUnset(): void
    {
        $this->configure();
        [, $out] = $this->cli(['config', 'list']);
        $this->assertStringContainsString('scope = org', $out);

        [$code] = $this->cli(['config', 'set', 'drain-timeout', '120']);
        $this->assertSame(0, $code);
        [, $out] = $this->cli(['config', 'get', 'drain-timeout']);
        $this->assertSame("120\n", $out);

        [$code, $out] = $this->cli(['config', 'set', 'auto-restart', 'on']);
        $this->assertSame(0, $code);
        [, $json] = $this->cli(['config', 'list', '--json']);
        $this->assertSame('1', json_decode($json, true)['settings']['auto-restart']);

        [$code] = $this->cli(['config', 'unset', 'drain-timeout']);
        $this->assertSame(0, $code);
        [, $out] = $this->cli(['config', 'get', 'drain-timeout']);
        $this->assertSame("\n", $out);
    }

    public function testConfigErrorsAreClear(): void
    {
        [$code, , $err] = $this->cli(['config', 'set', 'drain-timeout', '120']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('runnerdeck setup', $err);

        $this->configure();
        foreach (
            [
            [['config', 'set', 'drain-timeout', 'soon'], 'drain timeout must be'],
            [['config', 'set', 'bogus', '1'], 'unknown setting: bogus'],
            [['config', 'set', 'auto-restart', 'maybe'], 'takes on or off'],
            [['config', 'set', 'org', 'x'], 'runnerdeck setup --org'],
            [['config', 'set', 'drain-timeout'], 'usage: runnerdeck config set'],
            [['config', 'unset'], 'usage: runnerdeck config unset'],
            [['config', 'get', 'bogus'], 'unknown setting: bogus'],
            [['config', 'frobnicate'], 'usage: runnerdeck config list'],
            ] as [$argv, $message]
        ) {
            [$code, , $err] = $this->cli($argv);
            $this->assertSame(1, $code, implode(' ', $argv));
            $this->assertStringContainsString($message, $err, implode(' ', $argv));
        }
    }

    public function testConfigListAllShowsEveryName(): void
    {
        [, $out] = $this->cli(['config', 'list', '--all']);
        $this->assertStringContainsString("scope\n", $out);
        $this->assertStringContainsString("pool-cap\n", $out);
        $this->assertStringNotContainsString('totp', $out);
    }

    public function testAuthStatusLoginAndTotpUseTheTerminal(): void
    {
        Shell::fake(fn (array $cmd): array => ['code' => 0, 'stdout' => 'ok', 'stderr' => '']);
        [$code, $out] = $this->cli(['auth']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Signed in to GitHub', $out);

        Shell::fake(fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => 'nope']);
        [$code, , $err] = $this->cli(['auth', 'status']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Not signed in', $err);

        $ran = [];
        $terminal = function (array $cmd) use (&$ran): int {
            $ran[] = $cmd;
            return 0;
        };
        [$code, $out] = $this->cli(['auth', 'login'], '', $terminal);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('admin:org', $out);
        [$code] = $this->cli(['auth', 'totp'], '', $terminal);
        $this->assertSame(0, $code);
        $this->assertSame(['auth', 'login'], array_slice($ran[0], 1, 2));
        $this->assertStringEndsWith('setup-totp.php', $ran[1][1]);

        [$code] = $this->cli(['auth', 'login'], '', fn (array $cmd): int => 4);
        $this->assertSame(1, $code);
        [$code, , $err] = $this->cli(['auth', 'logout']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('usage: runnerdeck auth', $err);
    }

    public function testAKnownUpdateIsNamedBeforeTheCommand(): void
    {
        $version = \RunnerDeck\Configuration\Config::version();
        $dir = dirname((string) getenv('RUNNERDECK_SETTINGS_FILE'));
        file_put_contents($dir . '/cli-update.json', json_encode([
            'checked_at' => time(),
            'latest' => '99.0.0',
        ]));

        [$code, $out, $err] = $this->cli(['list', '--color']);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('99.0.0', $out);
        $this->assertStringContainsString("↑ RunnerDeck 99.0.0 is available (you have {$version})", $err);
        $this->assertStringContainsString('Run: runnerdeck update', $err);
        $this->assertStringContainsString('https://github.com/AnikethTS/RunnerDeck/releases/tag/v99.0.0', $err);

        [, , $quiet] = $this->cli(['list', '--color', '-q']);
        $this->assertStringNotContainsString('99.0.0', $quiet);
        [, $jsonOut, $jsonErr] = $this->cli(['list', '--color', '--json']);
        $this->assertStringNotContainsString('99.0.0', $jsonErr);
        $this->assertStringNotContainsString('is available', $jsonOut);
        [, , $plain] = $this->cli(['list']);
        $this->assertStringNotContainsString('99.0.0', $plain);

        [, , $updateErr] = $this->cli(['completion', 'bash', '--color']);
        $this->assertStringNotContainsString('99.0.0', $updateErr);
    }
}
