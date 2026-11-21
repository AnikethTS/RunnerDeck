<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

use RunnerDeck\Configuration\Config;
use RunnerDeck\Http\Api;
use RunnerDeck\Lifecycle\CrashState;
use RunnerDeck\Pool\ProcessControl;
use RunnerDeck\Presentation\Banner;
use RunnerDeck\Pool\RunnerInfo;
use RunnerDeck\Pool\RunnerLog;
use RunnerDeck\Pool\RunnerPool;

final class Cli
{
    private const USAGE = <<<'TXT'
Usage: runnerdeck <command> [options]

Runners:
  list, ls [--watch]           Show the runners on this machine
  status [--watch]             Like list, plus what GitHub reports for each
  start <runner...|--all>      Start runners
  stop <runner...|--all>       Stop runners (asks GitHub first; --force skips)
  restart <runner...|--all>   Stop, then start, runners
  rolling-restart <runner...|--all> [--timeout S]
                               Wait for each to be idle, then restart it
  drain <runner...|--all> [--timeout S]
                               Wait until idle, then stop and clear work folders
  add [--name N] [--ephemeral] [--label L]
                               Add and start a new runner
  delete <runner...>           Stop, deregister and remove runners
  rename <runner> <name>       Re-register a runner under a new name
  force-idle <runner>          Clear a stuck "busy" state on GitHub
  resize <count>               Make the pool this many runners, including runner-base
  maintenance on|off|status    Drain everything and refuse starts, or lift that
  logs <runner> [-n N] [-f]    Show a runner's log; -f follows it

Setup:
  setup [--scope S] [--org O | --repo OWNER/REPO]
                               Choose what RunnerDeck manages
  config list|get|set|unset    Read and change settings (see --all for names)
  auth status|login|totp       GitHub sign-in and dashboard TOTP login
  doctor                       Check this machine's setup

Program:
  run [--open] [PORT]          Start the dashboard (default port 8090)
  update [--check] [--yes]     Upgrade to the latest release (--check only looks)
  install [--dir D]            Link ./runnerdeck onto your PATH and set up tab completion
                               (source checkout; --no-completion skips completion)
  uninstall [--dir D]          Remove that link
  completion bash|zsh|fish|powershell
                               Print a shell completion script
  version                      Print the version

Options:
  --json         Machine-readable output
  --force        Do not check GitHub for a running job first
  --timeout S    How long to wait for a job to finish (seconds)
  --yes, -y      Do not ask before updating
  -q, --quiet    Print only errors
  --no-color     No colour (also NO_COLOR=1)
  -h, --help     Show this help
TXT;

    /** @var resource */
    private $out;
    /** @var resource */
    private $err;
    /** @var resource */
    private $in;
    private bool $json = false;
    private bool $force = false;
    private bool $quiet = false;
    private bool $color = false;
    private bool $interactive = false;

    /** @var null|callable(): bool Test seam: return false to end `logs -f`. */
    private $keepFollowing = null;

    /** @var null|callable(list<string>): int */
    private $terminalRunner = null;

    /**
     * @param resource|null $out
     * @param resource|null $err
     * @param resource|null $in
     */
    public function __construct($out = null, $err = null, $in = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->in = $in ?? STDIN;
    }

    /** @param null|callable(list<string>): int $runner Test seam for commands that run on your terminal. */
    public function runCommandsWith(?callable $runner): void
    {
        $this->terminalRunner = $runner;
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
        $this->quiet = isset($flags['quiet']) || isset($flags['q']);
        $this->interactive = isset($flags['color']) || $this->isTerminal($this->out);
        $this->color = !isset($flags['no-color']) && (
            isset($flags['color']) || (getenv('NO_COLOR') === false && $this->isTerminal($this->out))
        );
        if (isset($flags['help']) || isset($flags['h'])) {
            $this->say(self::USAGE);
            return 0;
        }

        $this->mentionUpdate($command);

        return match ($command) {
            'version', '--version', '-v' => $this->version(),
            'list', 'ls' => $this->list($flags, false),
            'status' => $this->list($flags, true),
            'start' => $this->start($flags, $args),
            'stop' => $this->stop($flags, $args),
            'rolling-restart' => $this->rollingRestart($flags, $args),
            'drain' => $this->drain($flags, $args),
            'resize' => $this->resize($args),
            'rename' => $this->rename($args),
            'force-idle' => $this->forceIdle($args),
            'maintenance' => $this->maintenance($flags, $args),
            'setup' => $this->setup($flags),
            'config' => $this->config($flags, $args),
            'auth' => $this->auth($args),
            'completion' => $this->completion($args),
            '__complete' => $this->complete($args),
            'install' => $this->install($flags),
            'uninstall' => $this->uninstall($flags),
            'update', 'upgrade' => $this->update($flags),
            'restart' => $this->restart($flags, $args),
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
        $valued = [
            'name' => true, 'label' => true, 'n' => true, 'lines' => true, 'timeout' => true,
            'interval' => true, 'scope' => true, 'org' => true, 'repo' => true, 'dir' => true,
        ];
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
        $message = str_replace('force=1', '--force', (string) ($result['message'] ?? ($ok ? 'ok' : 'failed')));
        if ($ok && $this->quiet) {
            return 0;
        }
        fwrite($ok ? $this->out : $this->err, $message . "\n");
        return $ok ? 0 : 1;
    }

    private function version(): int
    {
        $banner = $this->interactive && !$this->quiet && !$this->json && getenv('RUNNERDECK_NO_BANNER') !== '1';
        if ($banner) {
            fwrite($this->out, Banner::render($this->color));
            return 0;
        }
        return $this->say('RunnerDeck ' . Config::version());
    }

    private function mentionUpdate(string $command): void
    {
        if ($this->json || $this->quiet || !$this->interactive) {
            return;
        }
        if (in_array($command, ['completion', '__complete', 'update', 'upgrade'], true)) {
            return;
        }
        $text = UpdateNotice::line($this->isTerminal($this->out));
        if ($text === null || $text === '') {
            return;
        }
        fwrite($this->err, $this->paint('33', $text) . "\n");
    }

    /** @param resource $stream */
    private function isTerminal($stream): bool
    {
        return function_exists('stream_isatty') && @stream_isatty($stream);
    }

    private function paint(string $code, string $text): string
    {
        return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    /** @param array<string, mixed> $flags */
    private function list(array $flags, bool $withGithub): int
    {
        if (!isset($flags['watch'])) {
            return $this->listOnce($withGithub);
        }
        if ($this->json) {
            return $this->fail('--watch does not combine with --json');
        }
        $interval = max(1, (int) ($flags['interval'] ?? 5));
        while ($this->keepFollowing === null || ($this->keepFollowing)()) {
            if ($this->color) {
                fwrite($this->out, "\033[H\033[2J");
            }
            $this->listOnce($withGithub);
            usleep($this->keepFollowing === null ? $interval * 1000000 : 1000);
        }
        return 0;
    }

    private function listOnce(bool $withGithub): int
    {
        $github = $withGithub ? $this->githubRunners() : null;
        $rows = array_map(
            static fn (RunnerInfo $r): array => $r->toArray(),
            array_values(RunnerPool::discover(0))
        );
        foreach ($rows as &$row) {
            $row['state'] = !$row['configured'] ? 'new' : ($row['local_running'] ? 'running' : 'stopped');
            if ($withGithub) {
                $row['github'] = $github === null ? '?' : self::githubState($github[$row['agent_name']] ?? null);
            }
        }
        unset($row);
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
        $head = sprintf('%-14s %-9s %-7s %-8s', 'RUNNER', 'STATE', 'PID', 'UPTIME')
            . ($withGithub ? sprintf(' %-9s', 'GITHUB') : '') . ' NAME';
        $this->say($this->paint('1', $head));
        foreach ($rows as $r) {
            $state = $this->paint(
                ['running' => '32', 'stopped' => '33', 'new' => '36'][$r['state']],
                sprintf('%-9s', $r['state'])
            );
            $line = sprintf('%-14s ', $r['id']) . $state . sprintf(
                ' %-7s %-8s',
                $r['pid'] ?? '-',
                self::duration($r['uptime_seconds'])
            );
            if ($withGithub) {
                $line .= sprintf(' %-9s', $r['github']);
            }
            $this->say($line . ' ' . $r['agent_name'] . ($r['ephemeral'] ? ' (ephemeral)' : ''));
        }
        return 0;
    }

    /** @return array<string, array<string, mixed>>|null null when GitHub cannot be reached */
    private function githubRunners(): ?array
    {
        try {
            return \RunnerDeck\GitHub\GithubClient::listRunners();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @param array<string, mixed>|null $runner */
    private static function githubState(?array $runner): string
    {
        if ($runner === null) {
            return 'unregistered';
        }
        return ($runner['status'] ?? '') !== 'online' ? 'offline' : (!empty($runner['busy']) ? 'busy' : 'idle');
    }

    public static function duration(?int $seconds): string
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

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     */
    private function restart(array $flags, array $args): int
    {
        $runners = $this->select($flags, $args);
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
    private function update(array $flags): int
    {
        $updater = new Updater($this->out, $this->err, $this->in, $this->terminalRunner);
        return $updater->run(
            isset($flags['check']),
            isset($flags['yes']) || isset($flags['y']),
            $this->json,
            dirname(__DIR__, 3)
        );
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

    /** @param array<string, mixed> $flags */
    private function timeout(array $flags): int
    {
        $raw = $flags['timeout'] ?? null;
        return is_string($raw) && ctype_digit($raw) && (int) $raw >= 1
            ? min(3600, (int) $raw)
            : Config::drainTimeoutSeconds();
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     */
    private function rollingRestart(array $flags, array $args): int
    {
        $runners = $this->select($flags, $args);
        if ($runners === null) {
            return 1;
        }
        $this->note('Restarting one runner at a time, waiting for each to be idle...');
        return $this->report(Ops::rollingRestart($runners, $this->force, $this->timeout($flags)));
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     */
    private function drain(array $flags, array $args): int
    {
        $runners = $this->select($flags, $args);
        if ($runners === null) {
            return 1;
        }
        $this->note('Waiting for running jobs to finish...');
        return $this->report(Ops::drain($runners, $this->force, $this->timeout($flags)));
    }

    /** @param list<string> $args */
    private function resize(array $args): int
    {
        $count = $args[0] ?? '';
        if (!ctype_digit($count) || (int) $count < 1 || (int) $count > 30) {
            return $this->fail('usage: runnerdeck resize <count>  (1 to 30 runners, including runner-base)');
        }
        return $this->report(Ops::resize((int) $count));
    }

    /** @param list<string> $args */
    private function rename(array $args): int
    {
        if (count($args) !== 2) {
            return $this->fail('usage: runnerdeck rename <runner> <new-name>');
        }
        $runners = $this->select([], [$args[0]]);
        return $runners === null ? 1 : $this->report(Ops::rename($runners[0], trim($args[1]), $this->force));
    }

    /** @param list<string> $args */
    private function forceIdle(array $args): int
    {
        $runners = $this->select([], array_slice($args, 0, 1));
        return $runners === null ? 1 : $this->report(ProcessControl::forceIdle($runners[0]));
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     */
    private function maintenance(array $flags, array $args): int
    {
        $mode = $args[0] ?? 'status';
        if ($mode === 'status') {
            $on = \RunnerDeck\Host\HostMode::isMaintenance();
            return $this->report([
                'ok' => true,
                'maintenance' => $on,
                'message' => 'Maintenance is ' . ($on ? 'on' : 'off'),
            ]);
        }
        if ($mode !== 'on' && $mode !== 'off') {
            return $this->fail('usage: runnerdeck maintenance on|off|status');
        }
        if ($mode === 'on') {
            $this->note('Waiting for running jobs to finish...');
        }
        return $this->report(Ops::maintenance($mode === 'on', $this->force, $this->timeout($flags)));
    }

    /** @param array<string, mixed> $flags */
    private function setup(array $flags): int
    {
        $scope = is_string($flags['scope'] ?? null) ? $flags['scope'] : '';
        if ($scope === '' && is_string($flags['org'] ?? null)) {
            $scope = 'org';
        }
        if ($scope === '' && is_string($flags['repo'] ?? null)) {
            $scope = 'repo';
        }
        if ($scope === '') {
            $scope = $this->ask('Manage runners for an organization or a single repository? [org/repo] ');
        }
        $target = is_string($flags[$scope] ?? null) ? $flags[$scope] : '';
        if ($target === '' && ($scope === 'org' || $scope === 'repo')) {
            $target = $this->ask($scope === 'org' ? 'Organization name: ' : 'Repository (owner/repo): ');
        }
        $result = ConfigCommand::setup($scope, $target);
        if (!$result['ok'] || $this->json) {
            return $this->report($result);
        }
        $this->note($result['message']);
        $login = \RunnerDeck\GitHub\GithubClient::checkLogin();
        $this->note($login['logged_in']
            ? 'GitHub: signed in. Add a runner with: runnerdeck add'
            : 'GitHub: not signed in (' . $login['message'] . '). Run: runnerdeck auth login');
        return 0;
    }

    private function ask(string $question): string
    {
        fwrite($this->out, $question);
        $answer = fgets($this->in);
        return $answer === false ? '' : trim($answer);
    }

    private function note(string $text): void
    {
        if (!$this->quiet && !$this->json) {
            fwrite($this->out, $text . "\n");
        }
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string> $args
     */
    private function config(array $flags, array $args): int
    {
        $action = $args[0] ?? 'list';
        $name = $args[1] ?? '';
        $result = match ($action) {
            'list' => isset($flags['all'])
                ? ['ok' => true, 'settings' => ConfigCommand::names()]
                : ['ok' => true, 'settings' => ConfigCommand::all()],
            'get' => $this->configGet($name),
            'set' => count($args) >= 3 ? ConfigCommand::set($name, implode(' ', array_slice($args, 2)))
                : ['ok' => false, 'message' => 'usage: runnerdeck config set <name> <value>'],
            'unset' => $name !== '' ? ConfigCommand::unset($name)
                : ['ok' => false, 'message' => 'usage: runnerdeck config unset <name>'],
            default => ['ok' => false, 'message' => 'usage: runnerdeck config list|get|set|unset'],
        };
        if (!empty($result['ok']) && isset($result['settings']) && !$this->json) {
            foreach ((array) $result['settings'] as $key => $value) {
                $this->say(is_int($key) ? (string) $value : "{$key} = {$value}");
            }
            return 0;
        }
        if (!empty($result['ok']) && isset($result['value']) && !$this->json) {
            return $this->say((string) $result['value']);
        }
        return $this->report($result);
    }

    /** @return array<string, mixed> */
    private function configGet(string $name): array
    {
        if (!in_array($name, ConfigCommand::names(), true)) {
            return ['ok' => false, 'message' => "unknown setting: {$name}"];
        }
        $value = ConfigCommand::all()[$name] ?? '';
        return ['ok' => true, 'name' => $name, 'value' => $value];
    }

    /** @param list<string> $args */
    private function auth(array $args): int
    {
        $action = $args[0] ?? 'status';
        $runner = $this->terminalRunner ?? Updater::onTerminal(...);
        if ($action === 'status') {
            $login = \RunnerDeck\GitHub\GithubClient::checkLogin();
            return $this->report([
                'ok' => $login['logged_in'],
                'logged_in' => $login['logged_in'],
                'message' => $login['logged_in'] ? 'Signed in to GitHub.' : 'Not signed in: ' . $login['message'],
            ]);
        }
        if ($action === 'login') {
            $code = $runner([
                Config::ghBinary(), 'auth', 'login', '--hostname', 'github.com', '--web', '--git-protocol', 'https',
            ]);
            if ($code === 0 && Config::scope() === 'org') {
                $this->note('Org scope needs one more permission: gh auth refresh -h github.com -s admin:org');
            }
            return $code === 0 ? 0 : 1;
        }
        if ($action === 'totp') {
            return $runner([PHP_BINARY, dirname(__DIR__, 2) . '/bin/setup-totp.php']) === 0 ? 0 : 1;
        }
        return $this->fail('usage: runnerdeck auth status|login|totp');
    }

    /** @param list<string> $args */
    private function completion(array $args): int
    {
        $script = Completion::script($args[0] ?? '');
        if ($script === null) {
            return $this->fail('usage: runnerdeck completion ' . implode('|', Completion::SHELLS));
        }
        fwrite($this->out, $script);
        return 0;
    }

    /** Hidden helper the completion scripts call. @param list<string> $args */
    private function complete(array $args): int
    {
        $names = ($args[0] ?? '') === 'settings'
            ? ConfigCommand::names()
            : array_keys(RunnerPool::discover(0));
        foreach ($names as $name) {
            $this->say((string) $name);
        }
        return 0;
    }

    /** @param array<string, mixed> $flags */
    private function install(array $flags): int
    {
        $dir = is_string($flags['dir'] ?? null) ? $flags['dir'] : Installer::defaultDir();
        return $this->report(Installer::install(
            dirname(__DIR__, 3),
            $dir,
            $this->force,
            PHP_OS_FAMILY,
            !isset($flags['no-completion'])
        ));
    }

    /** @param array<string, mixed> $flags */
    private function uninstall(array $flags): int
    {
        $dir = is_string($flags['dir'] ?? null) ? $flags['dir'] : Installer::defaultDir();
        return $this->report(Installer::uninstall(dirname(__DIR__, 3), $dir));
    }
}
