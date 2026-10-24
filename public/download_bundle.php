<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Core\Auth;
use RunnerDeck\Core\Csrf;
use RunnerDeck\Core\HostBundle;

Auth::rejectPlainIfProtected();

$includeSecrets = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::verifyRequest()) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'invalid csrf token';
        exit;
    }
    $includeSecrets = ($_POST['include_secrets'] ?? '') === '1';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$payload = json_encode(HostBundle::export($includeSecrets), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'failed to encode host bundle';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="runnerdeck-host-bundle.json"');
header('Content-Length: ' . (string) strlen($payload));
// JSON attachment from json_encode of host state. htmlentities() would
// corrupt the file. include_secrets only chooses whether the webhook URL
// is in that JSON; it is never echoed as HTML.
// nosemgrep: php.lang.security.injection.echoed-request.echoed-request
echo $payload;
