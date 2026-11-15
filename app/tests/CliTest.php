<?php

declare(strict_types=1);

namespace Tests;

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
        $this->assertSame('RunnerDeck ' . trim((string) file_get_contents(__DIR__ . '/../../VERSION')) . "\n", $out);
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
}
