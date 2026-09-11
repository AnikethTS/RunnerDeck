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

$logFile = RunnerPool::dirFor($id) . '/runner.log';
if (!is_file($logFile)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'no log file yet';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $id . '-runner.log"');
header('Content-Length: ' . (string) filesize($logFile));
readfile($logFile);
