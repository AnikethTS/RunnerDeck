<?php

declare(strict_types=1);

namespace RunnerDeck\Http\Action\Monitoring;

use RunnerDeck\Http\Api as JsonApi;
use RunnerDeck\Configuration\Config;
use RunnerDeck\Presentation\Dashboard;
use RunnerDeck\Presentation\DashboardView;

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
            $snapshot['runners'] = self::withoutLogTails($snapshot['runners'] ?? null);
        }
        JsonApi::respond($snapshot);
    }

    /**
     * The poll already paints the log preview in rows_html. The live-log
     * window loads tails from a status call that omits view=1.
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutLogTails(mixed $runners): array
    {
        if (!is_array($runners)) {
            return [];
        }
        $out = [];
        foreach ($runners as $row) {
            if (!is_array($row)) {
                continue;
            }
            unset($row['log_tail']);
            $out[] = $row;
        }
        return $out;
    }
}
