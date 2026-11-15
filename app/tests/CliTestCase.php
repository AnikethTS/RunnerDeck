<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\Cli;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\System\Shell;

abstract class CliTestCase extends TestCase
{
    protected string $pool;

    protected function setUp(): void
    {
        $this->pool = sys_get_temp_dir() . '/runnerdeck-cli-case-' . uniqid();
        mkdir($this->pool . '/runner-1', 0755, true);
        mkdir($this->pool . '/runner-2', 0755, true);
        file_put_contents($this->pool . '/runner-1/runner.log', "one\ntwo\nthree\n");
        mkdir($this->pool . '-storage', 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->pool . '-storage/settings.json');
        putenv('RUNNERDECK_POOL_DIR=' . $this->pool);
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('NO_COLOR');
        RunnerPool::fakeLiveListeners([]);
        RunnerPool::fakeCheckProcess(fn () => [false, null]);
    }

    protected function tearDown(): void
    {
        Shell::fake(null);
        RunnerPool::fakeLiveListeners(null);
        RunnerPool::fakeCheckProcess(null);
        ProcessControl::fakeKill(null);
        ProcessControl::fakeAlive(null);
        ProcessControl::fakeTermWait(null);
        \RunnerDeck\Lifecycle\Drain::fakeSleep(null);
        \RunnerDeck\Lifecycle\Drain::fakeNow(null);
        $names = ['RUNNERDECK_POOL_DIR', 'RUNNERDECK_ORG', 'RUNNERDECK_SCOPE', 'RUNNERDECK_SETTINGS_FILE', 'NO_COLOR'];
        foreach ($names as $name) {
            putenv($name);
        }
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string, 2: string}
     */
    protected function cli(array $argv, string $input = '', ?callable $terminal = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);
        $cli = new Cli($out, $err, $in);
        $cli->runCommandsWith($terminal);
        $code = $cli->run($argv);
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    protected function githubBusy(?string $busyAgent = null): void
    {
        Shell::fake(function (array $cmd) use ($busyAgent): array {
            $runners = [];
            if ($busyAgent !== null) {
                $runners[] = ['name' => $busyAgent, 'status' => 'online', 'busy' => true,
                    'labels' => [], 'os' => 'Linux', 'id' => 1];
            }
            return ['code' => 0, 'stdout' => json_encode(['runners' => $runners]), 'stderr' => ''];
        });
    }

    protected function fastClock(): void
    {
        $clock = 1000;
        \RunnerDeck\Lifecycle\Drain::fakeNow(function () use (&$clock): int {
            return $clock;
        });
        \RunnerDeck\Lifecycle\Drain::fakeSleep(function (int $seconds) use (&$clock): void {
            $clock += $seconds;
        });
    }

    protected function githubDown(): void
    {
        Shell::fake(fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => 'offline']);
    }

    protected function configure(): void
    {
        \RunnerDeck\Configuration\Settings::save([
            'RUNNERDECK_SCOPE' => 'org',
            'RUNNERDECK_ORG' => 'acme',
        ]);
    }
}
