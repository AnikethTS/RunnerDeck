<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Core\Auth;
use RunnerDeck\Core\Csrf;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: login.php');
    exit;
}

if (Csrf::verifyRequest() && Auth::isEnabled()) {
    Auth::logout();
}

header('Location: login.php');
exit;
