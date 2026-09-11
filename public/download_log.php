<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$id = $_GET['runner'] ?? '';
if (!RunnerPool::isKnownId($id)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'unknown runner';
    exit;
}

// $id is whitelisted above via isKnownId()'s ^runner-(base|[0-9]+)$ regex
// plus a directory-existence check — not attacker-controlled by this point,
// for every use of $logFile below.
$logFile = RunnerPool::dirFor($id) . '/runner.log';
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
if (!is_file($logFile)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'no log file yet';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $id . '-runner.log"');
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
header('Content-Length: ' . (string) filesize($logFile));
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
readfile($logFile);
