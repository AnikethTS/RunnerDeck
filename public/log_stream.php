<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (Auth::isEnabled() && !Auth::isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'unauthorized';
    exit;
}

$id = $_GET['runner'] ?? '';
if (!RunnerPool::isKnownId($id)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'unknown runner';
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

// $id is whitelisted above via isKnownId()'s ^runner-(base|[0-9]+)$ regex
// plus a directory-existence check — not attacker-controlled by this point.
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
$logFile = RunnerPool::dirFor($id) . '/runner.log';

foreach (RunnerPool::tailLog($logFile, 50) as $line) {
    // SSE data, read via textContent in app.js (never innerHTML) — HTML-
    // escaping here would corrupt the log text for a client that doesn't
    // decode entities.
    // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
    echo 'data: ' . strtr($line, ["\r" => '', "\n" => '']) . "\n\n";
}
echo "\n";
@flush();

// $logFile is built from the same whitelisted $id as above; every use of
// it below is covered by that same reasoning.
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
$lastSize = is_file($logFile) ? filesize($logFile) : 0;
$deadline = time() + 1800;

while (!connection_aborted() && time() < $deadline) {
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
    clearstatcache(true, $logFile);
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
    $size = is_file($logFile) ? filesize($logFile) : 0;

    if ($size > $lastSize) {
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
        $fh = fopen($logFile, 'r');
        fseek($fh, $lastSize);
        $chunk = fread($fh, $size - $lastSize);
        fclose($fh);
        $lastSize = $size;
        foreach (explode("\n", rtrim($chunk, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            // Same SSE/textContent reasoning as the initial tail loop above.
            // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
            echo 'data: ' . strtr($line, ["\r" => '']) . "\n\n";
        }
    } elseif ($size < $lastSize) {
        $lastSize = 0;
    }

    echo ": keep-alive\n\n";
    @flush();
    sleep(1);
}
