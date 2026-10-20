<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\AgentRoster;
use RunnerDeck\Api as JsonApi;
use RunnerDeck\AppLog;
use RunnerDeck\GithubClient;
use RunnerDeck\RunnerPool;

final class RemoveOrphanAction
{
    public static function handle(): never
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            JsonApi::respond(['ok' => false, 'message' => 'name is required'], 422);
        }
        foreach (RunnerPool::discover(0) as $info) {
            if ($info->agentName === $name) {
                JsonApi::respond([
                    'ok' => false,
                    'message' => $name . ' still has a local slot — delete the runner instead',
                ], 409);
            }
        }
        if (!in_array($name, AgentRoster::names(), true)) {
            JsonApi::respond([
                'ok' => false,
                'message' => $name . ' is not a runner this host registered',
            ], 404);
        }

        try {
            $gh = GithubClient::listRunners()[$name] ?? null;
        } catch (\RuntimeException $e) {
            JsonApi::respond(['ok' => false, 'message' => $e->getMessage()], 502);
        }
        if ($gh === null) {
            AgentRoster::forget($name);
            JsonApi::respond(['ok' => true, 'message' => $name . ' was already gone from GitHub']);
        }
        if (!empty($gh['busy']) && (($_POST['force'] ?? '') !== '1')) {
            JsonApi::respond([
                'ok' => false,
                'busy' => true,
                'message' => $name . ' is busy on GitHub. Pass force=1 to remove anyway.',
            ], 409);
        }
        try {
            GithubClient::deleteRunner((int) $gh['id']);
        } catch (\RuntimeException $e) {
            JsonApi::respond(['ok' => false, 'message' => $e->getMessage()], 502);
        }
        AgentRoster::forget($name);
        AppLog::info('gh.remove_orphan', $name . ' removed from GitHub');
        JsonApi::respond(['ok' => true, 'message' => $name . ' removed from GitHub']);
    }
}
