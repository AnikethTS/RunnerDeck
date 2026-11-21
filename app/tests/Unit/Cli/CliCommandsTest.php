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
            'auth', 'update', 'install', 'completion', '--watch', '--quiet', '--no-color'] as $word
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

        [$code, $out] = $this->cli(['uninstall', '--dir', $dir]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Removed', $out);
        $this->assertFileDoesNotExist($dir . '/runnerdeck');
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
