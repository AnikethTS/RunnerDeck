#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Config;
use RunnerDeck\GithubClient;
use RunnerDeck\Shell;

$color = function_exists('stream_isatty') && stream_isatty(STDOUT);

function paint(string $code, string $text, bool $color): string
{
    return $color ? "\033[{$code}m{$text}\033[0m" : $text;
}

function ok(string $label, string $detail, bool $color): int
{
    echo '  ' . paint('32', '[ OK ]', $color) . " {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    return 0;
}

/** @return int always 1, so a caller can accumulate a warning count with `$warnings += warn(...)` */
function warn(string $label, string $detail, bool $color): int
{
    echo '  ' . paint('33', '[WARN]', $color) . " {$label} — {$detail}\n";
    return 1;
}

/** @return int always 1, so a caller can accumulate a failure count with `$failures += fail(...)` */
function fail(string $label, string $detail, bool $color): int
{
    echo '  ' . paint('31', '[FAIL]', $color) . " {$label} — {$detail}\n";
    return 1;
}

function commandExists(string $bin): bool
{
    return Shell::exec(['which', $bin], 5)['code'] === 0;
}

echo "RunnerDeck doctor\n";
$failures = 0;
$warnings = 0;

echo "\nPHP\n";
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    ok('PHP version', PHP_VERSION, $color);
} else {
    $failures += fail('PHP version', PHP_VERSION . ' — RunnerDeck needs 8.1 or newer', $color);
}
foreach (['posix', 'pcntl'] as $ext) {
    if (extension_loaded($ext)) {
        ok("ext-{$ext}", '', $color);
    } else {
        $failures += fail("ext-{$ext}", 'required for process liveness checks and stopping runners', $color);
    }
}
if (extension_loaded('pdo_sqlite')) {
    ok('ext-pdo_sqlite', '', $color);
} else {
    $warnings += warn('ext-pdo_sqlite', 'optional — the CPU/RAM history chart stays empty without it', $color);
}

echo "\nExternal tools\n";
if (commandExists('bash')) {
    ok('bash', '', $color);
} else {
    $failures += fail('bash', 'run.sh needs bash — Alpine and some minimal distros don\'t ship it by default', $color);
}
if (commandExists('curl')) {
    ok('curl', '', $color);
} else {
    $failures += fail('curl', 'needed to download the runner package on first start', $color);
}
if (commandExists('tar')) {
    ok('tar', '', $color);
} else {
    $failures += fail('tar', 'needed to extract the downloaded runner package', $color);
}

$gh = Config::ghBinary();
$auth = null;
if (is_executable($gh) || commandExists($gh)) {
    ok('gh CLI', $gh, $color);

    if (Config::isConfigured()) {
        // authStatus() also probes org/repo access, which needs a scope to be set.
        $auth = GithubClient::authStatus();
        if ($auth->loggedIn) {
            ok('gh auth status', 'logged in', $color);
        } else {
            $failures += fail('gh auth status', $auth->message . ' — run `gh auth login`', $color);
        }
    } else {
        $login = Shell::exec([$gh, 'auth', 'status'], 10);
        if ($login['code'] === 0) {
            ok('gh auth status', 'logged in', $color);
        } else {
            $detail = trim($login['stderr'] ?: $login['stdout']) . ' — run `gh auth login`';
            $failures += fail('gh auth status', $detail, $color);
        }
    }
} else {
    $failures += fail('gh CLI', 'not found — install from https://cli.github.com/', $color);
}

echo "\nConfiguration\n";
if (!Config::isConfigured()) {
    $warnings += warn(
        'scope',
        'not configured yet — open the app and use the first-run setup screen, or set RUNNERDECK_ORG/RUNNERDECK_REPO',
        $color
    );
} else {
    $scope = Config::scope();
    $identifier = $scope === 'repo' ? Config::repo() : Config::org();
    ok('scope', "{$scope} ({$identifier})", $color);

    if ($auth !== null && $auth->loggedIn) {
        if ($auth->orgAccessOk) {
            ok('GitHub API access', "can read {$scope} runners", $color);
        } else {
            $failures += fail('GitHub API access', $auth->message, $color);
        }
    }
}

echo "\nFilesystem\n";
$poolDir = Config::poolDir();
if (is_dir($poolDir) && is_writable($poolDir)) {
    ok('pool directory', $poolDir, $color);
} elseif (!is_dir($poolDir) && is_writable(dirname($poolDir))) {
    ok('pool directory', "{$poolDir} (will be created on first start)", $color);
} else {
    $failures += fail('pool directory', "{$poolDir} is not writable", $color);
}

$storageDir = dirname(__DIR__) . '/storage';
if ((is_dir($storageDir) && is_writable($storageDir)) || (!is_dir($storageDir) && is_writable(dirname($storageDir)))) {
    ok('storage directory', $storageDir, $color);
} else {
    $detail = "{$storageDir} is not writable — settings and history won't save";
    $failures += fail('storage directory', $detail, $color);
}

echo "\n";
if ($failures > 0) {
    echo paint('31', "{$failures} check(s) failed", $color) . ($warnings > 0 ? ", {$warnings} warning(s)" : '') . ".\n";
    exit(1);
}
if ($warnings > 0) {
    echo paint('33', "All required checks passed, {$warnings} warning(s).", $color) . "\n";
    exit(0);
}
echo paint('32', 'All checks passed.', $color) . "\n";
