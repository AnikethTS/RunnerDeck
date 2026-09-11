<?php

require __DIR__ . '/../src/Config.php';
Config::bootstrapEnv();
require __DIR__ . '/../src/RunnerPool.php';

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

$logFile = RunnerPool::dirFor($id) . '/runner.log';

foreach (RunnerPool::tailLog($logFile, 50) as $line) {
    echo 'data: ' . strtr($line, ["\r" => '', "\n" => '']) . "\n\n";
}
echo "\n";
@flush();

$lastSize = is_file($logFile) ? filesize($logFile) : 0;
$deadline = time() + 1800;

while (!connection_aborted() && time() < $deadline) {
    clearstatcache(true, $logFile);
    $size = is_file($logFile) ? filesize($logFile) : 0;

    if ($size > $lastSize) {
        $fh = fopen($logFile, 'r');
        fseek($fh, $lastSize);
        $chunk = fread($fh, $size - $lastSize);
        fclose($fh);
        $lastSize = $size;
        foreach (explode("\n", rtrim($chunk, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            echo 'data: ' . strtr($line, ["\r" => '']) . "\n\n";
        }
    } elseif ($size < $lastSize) {
        $lastSize = 0;
    }

    echo ": keep-alive\n\n";
    @flush();
    sleep(1);
}
