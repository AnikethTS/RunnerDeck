#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/** @var list<string> $args */
$args = array_map('strval', array_slice((array) ($_SERVER['argv'] ?? []), 1));
$command = $args[0] ?? '';

if ($command === 'doctor') {
    require __DIR__ . '/doctor.php';
    exit(0);
}

if ($command === 'run' && PHP_OS_FAMILY === 'Windows') {
    fwrite(STDERR, "runnerdeck: start the dashboard with RunnerDeck.exe\n");
    exit(1);
}

if ($command === 'run') {
    $ports = array_filter(array_slice($args, 1), static fn (string $a): bool => ctype_digit($a));
    $parts = [dirname(__DIR__, 2) . '/run.sh', ...$ports];
    passthru(implode(' ', array_map(static fn (string $a): string => escapeshellarg($a), $parts)), $code);
    exit($code);
}

exit((new \RunnerDeck\Cli\Cli())->run($args));
