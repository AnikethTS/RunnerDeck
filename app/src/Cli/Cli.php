<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

use RunnerDeck\Configuration\Config;
use RunnerDeck\Http\Api;
use RunnerDeck\Lifecycle\CrashState;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Pool\RunnerInfo;
use RunnerDeck\Pool\RunnerLog;
use RunnerDeck\Pool\RunnerPool;

/**
 * Headless command line for the runner pool. It calls the same classes the
 * dashboard's API actions call, so it works without the dashboard running.
 */
final class Cli
{
    private const USAGE = <<<'TXT'
Usage: runnerdeck <command> [options]

Commands:
  run [--open] [PORT]          Start the dashboard (default port 8090)
  list, ls, status             Show the runners on this machine
  start <runner...|--all>      Start runners
  stop <runner...|--all>       Stop runners (asks GitHub first; --force skips)
  restart <runner...>          Stop, then start, runners
  add [--name N] [--ephemeral] [--label L]
                               Add and start a new runner
  delete <runner...>           Stop, deregister and remove runners
  logs <runner> [-n N] [-f]    Show a runner's log; -f follows it
  doctor                       Check this machine's setup
  version                      Print the version

Options:
  --json     Machine-readable output (list, start, stop, restart, add, delete)
  --force    Do not check GitHub for a running job first
  -h, --help Show this help
TXT;

    /** @var resource */
    private $out;
    /** @var resource */
    private $err;
    private bool $json = false;
    private bool $force = false;

    /** @var null|callable(): bool Test seam: return false to end `logs -f`. */
    private $keepFollowing = null;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct($out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    public function followWhile(?callable $keepFollowing): void
    {
        $this->keepFollowing = $keepFollowing;
    }

    /** @param list<string> $argv arguments after the program name */
    public function run(array $argv): int
    {
        $command = array_shift($argv);
        if ($command === null || $command === 'help' || $command === '--help' || $command === '-h') {
            $this->say(self::USAGE);
            return 0;
        }
        [$flags, $args] = $this->parse($argv);
        $this->json = isset($flags['json']);
        $this->force = isset($flags['force']);
        if (isset($flags['help']) || isset($flags['h'])) {
            $this->say(self::USAGE);
            return 0;
        }

        return match ($command) {
            'version', '--version', '-v' => $this->say('RunnerDeck ' . Config::version()),
            'list', 'ls', 'status' => $this->list(),
            'start' => $this->start($flags, $args),
            'stop' => $this->stop($flags, $args),
            'restart' => $this->restart($args),
            'add' => $this->add($flags),
            'delete', 'rm' => $this->delete($args),
            'logs' => $this->logs($flags, $args),
            default => $this->fail("unknown command: {$command}\n\n" . self::USAGE),
        };
    }

    /**
     * @param list<string> $argv
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function parse(array $argv): array
    {
        $valued = ['name' => true, 'label' => true, 'n' => true, 'lines' => true];
        $flags = [];
        $args = [];
        for ($i = 0, $n = count($argv); $i < $n; $i++) {
            $a = $argv[$i];
            if (!str_starts_with($a, '-') || $a === '-') {
                $args[] = $a;
                continue;
            }
            $name = ltrim($a, '-');
            $value = true;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            } elseif (isset($valued[$name]) && $i + 1 < $n) {
                $value = $argv[++$i];
            }
            if ($name === 'label') {
                $flags['label'] = array_merge((array) ($flags['label'] ?? []), [(string) $value]);
            } else {
                $flags[$name] = $value;
            }
        }
        return [$flags, $args];
    }

    private function say(string $text): int
    {
        fwrite($this->out, $text . "\n");
        return 0;
    }

    private function fail(string $text): int
    {
        if ($this->json) {
            fwrite($this->out, json_encode(['ok' => false, 'message' => $text]) . "\n");
        } else {
            fwrite($this->err, "runnerdeck: {$text}\n");
        }
        return 1;
    }

    /** @param array<string, mixed> $result */
    private function report(array $result): int
    {
        $ok = !empty($result['ok']);
        if ($this->json) {
            $encoded = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            fwrite($this->out, $encoded . "\n");
            return $ok ? 0 : 1;
        }
        $message = (string) ($result['message'] ?? ($ok ? 'ok' : 'failed'));
        fwrite($ok ? $this->out : $this->err, $message . "\n");
        return $ok ? 0 : 1;
    }

    private function list(): int
    {
        $rows = array_map(static fn (RunnerInfo $r): array => $r->toArray(), array_values(RunnerPool::discover(1)));
        if ($this->json) {
            $slim = array_map(static function (array $r): array {
                unset($r['log_tail']);
                return $r;
            }, $rows);
            return $this->say((string) json_encode(['ok' => true, 'runners' => $slim]));
        }
        if ($rows === []) {
            return $this->say('No runners yet. Add one with: runnerdeck add');
        }
        $this->say(sprintf('%-14s %-9s %-7s %-8s %s', 'RUNNER', 'STATE', 'PID', 'UPTIME', 'NAME'));
        foreach ($rows as $r) {
            $state = !$r['configured'] ? 'new' : ($r['local_running'] ? 'running' : 'stopped');
            $this->say(sprintf(
                '%-14s %-9s %-7s %-8s %s',
                $r['id'],
                $state,
                $r['pid'] ?? '-',
                self::duration($r['uptime_seconds']),
                $r['agent_name'] . ($r['ephemeral'] ? ' (ephemeral)' : '')
            ));
        }
        return 0;
    }

    private static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }
        return match (true) {
            $seconds >= 86400 => intdiv($seconds, 86400) . 'd' . intdiv($seconds % 86400, 3600) . 'h',
            $seconds >= 3600 => intdiv($seconds, 3600) . 'h' . intdiv($seconds % 3600, 60) . 'm',
            $seconds >= 60 => intdiv($seconds, 60) . 'm',
            default => $seconds . 's',
        };
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     * @return list<RunnerInfo>|null null after printing an error
     */
    private function select(array $flags, array $args): ?array
    {
        $all = RunnerPool::discover(0);
        if (isset($flags['all'])) {
            return array_values($all);
        }
        if ($args === []) {
            $this->fail('name at least one runner (see `runnerdeck list`), or pass --all');
            return null;
        }
        $unknown = array_values(array_filter($args, static fn (string $id): bool => !isset($all[$id])));
        if ($unknown !== []) {
            $this->fail('unknown runner(s): ' . implode(', ', $unknown));
            return null;
        }
        return array_map(static fn (string $id): RunnerInfo => $all[$id], $args);
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     */
    private function start(array $flags, array $args): int
    {
        $runners = $this->select($flags, $args);
        return $runners === null ? 1 : $this->report(ProcessControl::bulkStart($runners));
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     */
    private function stop(array $flags, array $args): int
    {
        $runners = $this->select($flags, $args);
        if ($runners === null) {
            return 1;
        }
        $blocked = Api::checkNoneBusy($runners, $this->force);
        if ($blocked !== null) {
            return $this->report($blocked);
        }
        Api::markExplicitlyStopped($runners);
        return $this->report(ProcessControl::bulkStop($runners));
    }

    /** @param list<string> $args */
    private function restart(array $args): int
    {
        $runners = $this->select([], $args);
        if ($runners === null) {
            return 1;
        }
        $blocked = Api::checkNoneBusy($runners, $this->force);
        if ($blocked !== null) {
            return $this->report($blocked);
        }
        $messages = [];
        $ok = true;
        foreach ($runners as $r) {
            $result = ProcessControl::restartIndividual($r);
            $ok = $ok && !empty($result['ok']);
            $messages[] = (string) ($result['message'] ?? '');
        }
        return $this->report(['ok' => $ok, 'message' => implode("\n", $messages)]);
    }

    /** @param array<string, mixed> $flags */
    private function add(array $flags): int
    {
        $labels = [];
        if (isset($flags['label'])) {
            $labels = Config::parseLabels(implode(',', (array) $flags['label']));
            if ($labels === null) {
                return $this->fail('labels must be up to 12 names (letters, digits, dot, underscore, hyphen)');
            }
        }
        $name = isset($flags['name']) && is_string($flags['name']) ? trim($flags['name']) : '';
        $ephemeral = isset($flags['ephemeral']);
        return $this->report(ProcessControl::addRunner($name !== '' ? $name : null, $ephemeral, $labels));
    }

    /** @param list<string> $args */
    private function delete(array $args): int
    {
        $runners = $this->select([], $args);
        if ($runners === null) {
            return 1;
        }
        $blocked = Api::checkNoneBusy($runners, $this->force);
        if ($blocked !== null) {
            return $this->report($blocked);
        }
        $ok = true;
        $messages = [];
        foreach ($runners as $r) {
            CrashState::markExplicitlyStopped($r->id);
            $result = ProcessControl::deleteRunner($r);
            $ok = $ok && !empty($result['ok']);
            $messages[] = (string) ($result['message'] ?? '');
        }
        return $this->report(['ok' => $ok, 'message' => implode("\n", $messages)]);
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     */
    private function logs(array $flags, array $args): int
    {
        $id = $args[0] ?? '';
        if (!RunnerPool::isKnownId($id)) {
            return $this->fail($id === '' ? 'usage: runnerdeck logs <runner> [-n N] [-f]' : "unknown runner: {$id}");
        }
        $lines = max(1, min(2000, (int) ($flags['n'] ?? $flags['lines'] ?? 50)));
        $dir = RunnerPool::dirFor($id);
        $shown = RunnerLog::tail($dir, $lines);
        foreach ($shown as $line) {
            $this->say($line);
        }
        if (!isset($flags['f']) && !isset($flags['follow'])) {
            return 0;
        }
        while ($this->keepFollowing === null || ($this->keepFollowing)()) {
            usleep($this->keepFollowing === null ? 1000000 : 1000);
            $now = RunnerLog::tail($dir, 200);
            // Print what is new: everything after the last line we showed.
            $last = $shown === [] ? null : end($shown);
            $from = 0;
            if ($last !== null) {
                $at = array_keys($now, $last, true);
                $from = $at === [] ? 0 : (int) end($at) + 1;
            }
            foreach (array_slice($now, $from) as $line) {
                $this->say($line);
            }
            $shown = $now;
        }
        return 0;
    }
}
