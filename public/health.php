<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\HostMode;
use RunnerDeck\HostPressure;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pressure = HostPressure::snapshot();
$maintenance = HostMode::isMaintenance();
$ready = $pressure['ok'] && !$maintenance;

echo json_encode([
    'ok' => true,
    'ready' => $ready,
    'maintenance' => $maintenance,
    'pressure' => [
        'ok' => $pressure['ok'],
        'reasons' => $pressure['reasons'],
        'disk_free_kb' => $pressure['disk_free_kb'],
        'ram_available_kb' => $pressure['ram_available_kb'],
    ],
], JSON_UNESCAPED_SLASHES);
