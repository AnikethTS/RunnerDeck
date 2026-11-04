<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Core\Auth;
use RunnerDeck\Host\HostBundleSnapshot;

Auth::rejectPlainIfProtected();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$day = (string) ($_GET['day'] ?? '');
$path = HostBundleSnapshot::pathFor($day);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'snapshot not found';
    exit;
}

// $path is host-YYYY-MM-DD.json under storage/bundles after isDay() and
// realpath prefix checks in HostBundleSnapshot::pathFor() — not a free path.
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
$payload = file_get_contents($path);
if ($payload === false) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'failed to read snapshot';
    exit;
}

$name = 'runnerdeck-host-' . $day . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . (string) strlen($payload));
// File already stored as JSON. htmlentities() would corrupt the download.
// nosemgrep: php.lang.security.injection.echoed-request.echoed-request
echo $payload;
