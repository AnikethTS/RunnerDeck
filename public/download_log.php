<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Auth;
use RunnerDeck\RunnerPool;

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

$from = isset($_GET['from']) && $_GET['from'] !== '' ? (int) $_GET['from'] : null;
$to = isset($_GET['to']) && $_GET['to'] !== '' ? (int) $_GET['to'] : null;

header('Content-Type: text/plain; charset=utf-8');
$filename = $from === null && $to === null
    ? "{$id}-runner.log"
    : "{$id}-runner-" . ($from ?? 'start') . '-to-' . ($to ?? 'end') . '.log';
header('Content-Disposition: attachment; filename="' . $filename . '"');

if ($from === null && $to === null) {
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
    header('Content-Length: ' . (string) filesize($logFile));
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
    readfile($logFile);
    exit;
}

// The GitHub Actions runner console log timestamps status lines (e.g.
// "2026-09-12 10:15:23Z: Listening for Jobs") but not every line — job
// output in between has none. Each line inherits the most recently seen
// timestamp, so "from"/"to" reflect what was happening at that point in
// the log even for untimestamped lines.
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
$fh = fopen($logFile, 'r');
if ($fh === false) {
    http_response_code(500);
    exit;
}
$lastTs = null;
while (($line = fgets($fh)) !== false) {
    if (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/', $line, $m)) {
        $parsed = strtotime($m[1] . ' UTC');
        if ($parsed !== false) {
            $lastTs = $parsed;
        }
    }

    if ($from !== null && ($lastTs === null || $lastTs < $from)) {
        continue;
    }
    if ($to !== null && $lastTs !== null && $lastTs > $to) {
        continue;
    }
    // Served as text/plain with Content-Disposition: attachment (see
    // above) — never rendered as HTML, so htmlentities() here would just
    // corrupt the downloaded file's content.
    // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
    echo $line;
}
fclose($fh);
