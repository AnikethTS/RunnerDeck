<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Host\HostMode;
use RunnerDeck\Host\HostPressure;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pressure = HostPressure::snapshot();
$maintenance = HostMode::isMaintenance();
$rebootReady = HostMode::isRebootReady();
$ready = $pressure['ok'] && !$maintenance && !$rebootReady;

echo json_encode([
    'ok' => true,
    'ready' => $ready,
    'maintenance' => $maintenance,
    'reboot_ready' => $rebootReady,
    'pressure' => [
        'ok' => $pressure['ok'],
        'reasons' => $pressure['reasons'],
        'disk_free_kb' => $pressure['disk_free_kb'],
        'ram_available_kb' => $pressure['ram_available_kb'],
    ],
], JSON_UNESCAPED_SLASHES);
