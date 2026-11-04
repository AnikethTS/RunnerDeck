<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Security\Auth;
use RunnerDeck\Security\Csrf;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: login.php');
    exit;
}

if (Csrf::verifyRequest() && Auth::isEnabled()) {
    Auth::logout();
}

header('Location: login.php');
exit;
