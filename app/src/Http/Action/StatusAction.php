<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Core\Config;
use RunnerDeck\Ui\Dashboard;
use RunnerDeck\Ui\DashboardView;

final class StatusAction
{
    public static function handle(): never
    {
        if (!Config::isConfigured()) {
            JsonApi::respond(['ok' => false, 'message' => 'RunnerDeck is not configured yet'], 409);
        }
        $lines = max(0, min(500, (int) ($_GET['lines'] ?? 15)));
        $snapshot = Dashboard::snapshot($lines);
        if (($_GET['view'] ?? '') === '1') {
            $snapshot['view'] = DashboardView::pollView($snapshot);
        }
        JsonApi::respond($snapshot);
    }
}
