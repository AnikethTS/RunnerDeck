<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Auth;
use RunnerDeck\SettingsPortable;

Auth::rejectPlainIfProtected();

$payload = json_encode(SettingsPortable::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'failed to encode settings';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="runnerdeck-settings.json"');
header('Content-Length: ' . (string) strlen($payload));
echo $payload;
