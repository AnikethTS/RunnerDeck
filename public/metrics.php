<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Metrics;

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store');

echo Metrics::text();
