<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\AppLog;
use RunnerDeck\Auth;

Auth::rejectPlainIfProtected();

$files = AppLog::files();
if ($files === []) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'no log file yet';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="runnerdeck.log"');

$length = 0;
foreach ($files as $logFile) {
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
    $length += (int) filesize($logFile);
}
header('Content-Length: ' . (string) $length);
foreach ($files as $logFile) {
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
    readfile($logFile);
}
