<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\RunnerPool;

final class LogAction
{
    public static function handle(): never
    {
        $id = $_GET['runner'] ?? '';
        if (!RunnerPool::isKnownId($id)) {
            JsonApi::respond(['ok' => false, 'message' => "unknown runner: {$id}"], 404);
        }
        $lines = max(1, min(2000, (int) ($_GET['lines'] ?? 200)));
        $tail = RunnerPool::tailLog(RunnerPool::dirFor($id) . '/runner.log', $lines);
        JsonApi::respond(['ok' => true, 'lines' => $tail]);
    }
}
