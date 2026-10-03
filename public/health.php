<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
