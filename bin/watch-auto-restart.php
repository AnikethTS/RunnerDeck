#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\AutoRestart;
use RunnerDeck\Config;

$argv = $_SERVER['argv'] ?? [];
$once = in_array('--once', $argv, true);
$interval = 5;

do {
    if (Config::isConfigured() && Config::autoRestartEnabled()) {
        AutoRestart::tick();
    }
    if ($once) {
        break;
    }
    sleep($interval);
} while (true);
