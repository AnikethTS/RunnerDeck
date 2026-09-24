#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Config;
use RunnerDeck\CrashState;
use RunnerDeck\Drain;
use RunnerDeck\ProcessControl;
use RunnerDeck\RunnerPool;

if (!Config::isConfigured()) {
    fwrite(STDERR, "RunnerDeck is not configured yet.\n");
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
$force = in_array('--force', $argv, true);
$timeout = Config::drainTimeoutSeconds();
$ids = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        continue;
    }
    if (str_starts_with($arg, '--timeout=')) {
        $raw = substr($arg, 10);
        if (ctype_digit($raw) && (int) $raw >= 1) {
            $timeout = min(3600, (int) $raw);
        }
        continue;
    }
    $ids[] = $arg;
}

$all = RunnerPool::discover(0);
$targets = $ids === [] ? $all : [];
foreach ($ids as $id) {
    if (!isset($all[$id])) {
        fwrite(STDERR, "unknown runner: {$id}\n");
        exit(1);
    }
    $targets[$id] = $all[$id];
}

set_time_limit($timeout + 30);

if (!$force) {
    $wait = Drain::waitUntilNoneBusy($targets, $timeout);
    if (empty($wait['ok'])) {
        fwrite(STDERR, (string) ($wait['message'] ?? 'drain failed') . "\n");
        exit(2);
    }
}

foreach ($targets as $r) {
    CrashState::markExplicitlyStopped($r->id);
    $result = ProcessControl::stopIndividual($r);
    echo $result['message'] . "\n";
    if (!$result['ok']) {
        exit(1);
    }
}
