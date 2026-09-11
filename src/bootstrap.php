<?php

declare(strict_types=1);

require __DIR__ . '/Settings.php';
require __DIR__ . '/History.php';
require __DIR__ . '/Config.php';
Config::bootstrapEnv();
require __DIR__ . '/Shell.php';
require __DIR__ . '/RunnerPool.php';
require __DIR__ . '/SystemStats.php';
require __DIR__ . '/GithubClient.php';
require __DIR__ . '/UpdateCheck.php';
require __DIR__ . '/Provisioner.php';
require __DIR__ . '/ProcessControl.php';
require __DIR__ . '/Dashboard.php';
require __DIR__ . '/Csrf.php';
