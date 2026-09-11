<?php

declare(strict_types=1);

// Single load point for all entry points (public/*.php) so adding a class
// means editing this file once instead of every entry point in sync.

require __DIR__ . '/Settings.php';
require __DIR__ . '/Config.php';
Config::bootstrapEnv();
require __DIR__ . '/Shell.php';
require __DIR__ . '/RunnerPool.php';
require __DIR__ . '/GithubClient.php';
require __DIR__ . '/Provisioner.php';
require __DIR__ . '/ProcessControl.php';
require __DIR__ . '/Dashboard.php';
require __DIR__ . '/Csrf.php';
