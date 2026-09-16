<?php

declare(strict_types=1);

final class CsrfTokenAction
{
    public static function handle(): never
    {
        Api::respond(['ok' => true, 'token' => Csrf::token()]);
    }
}

final class StatusAction
{
    public static function handle(): never
    {
        if (!Config::isConfigured()) {
            Api::respond(['ok' => false, 'message' => 'RunnerDeck is not configured yet'], 409);
        }
        $lines = max(0, min(500, (int) ($_GET['lines'] ?? 15)));
        Api::respond(Dashboard::snapshot($lines));
    }
}

final class LogAction
{
    public static function handle(): never
    {
        $id = $_GET['runner'] ?? '';
        if (!RunnerPool::isKnownId($id)) {
            Api::respond(['ok' => false, 'message' => "unknown runner: {$id}"], 404);
        }
        $lines = max(1, min(2000, (int) ($_GET['lines'] ?? 200)));
        $tail = RunnerPool::tailLog(RunnerPool::dirFor($id) . '/runner.log', $lines);
        Api::respond(['ok' => true, 'lines' => $tail]);
    }
}

final class HistoryAction
{
    public static function handle(): never
    {
        Api::respond(['ok' => true, 'available' => History::isAvailable(), 'samples' => History::recent()]);
    }
}

final class SystemAction
{
    public static function handle(): never
    {
        Api::respond(['ok' => true, 'system' => SystemStats::snapshot()]);
    }
}

final class CheckUpdatesAction
{
    public static function handle(): never
    {
        if (!Config::checkUpdatesEnabled()) {
            Api::respond(['ok' => false, 'message' => 'update checks are disabled'], 403);
        }
        Api::respond(['ok' => true] + UpdateCheck::check());
    }
}
