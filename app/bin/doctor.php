#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Cli\Doctor;

/** @var list<string> $argv */
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));
exit(Doctor::fromArgv($argv)->run());
