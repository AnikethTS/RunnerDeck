<?php

declare(strict_types=1);

namespace RunnerDeck;

use RunnerDeck\Api\AddRunnerAction;
use RunnerDeck\Api\BulkDeleteAction;
use RunnerDeck\Api\BulkStartAction;
use RunnerDeck\Api\BulkStopAction;
use RunnerDeck\Api\CheckUpdatesAction;
use RunnerDeck\Api\CsrfTokenAction;
use RunnerDeck\Api\DeleteRunnerAction;
use RunnerDeck\Api\HistoryAction;
use RunnerDeck\Api\LogAction;
use RunnerDeck\Api\LogoutAction;
use RunnerDeck\Api\RenameAction;
use RunnerDeck\Api\ResizeAction;
use RunnerDeck\Api\RestartAction;
use RunnerDeck\Api\SaveSettingsAction;
use RunnerDeck\Api\StartAction;
use RunnerDeck\Api\StartAllAction;
use RunnerDeck\Api\StatusAction;
use RunnerDeck\Api\StopAction;
use RunnerDeck\Api\StopAllAction;
use RunnerDeck\Api\SystemAction;
use RunnerDeck\Api\TotpBeginAction;
use RunnerDeck\Api\TotpConfirmAction;

final class Api
{
    /** @var array<string, class-string> */
    private const GET_ACTIONS = [
        'status' => StatusAction::class,
        'log' => LogAction::class,
        'history' => HistoryAction::class,
        'system' => SystemAction::class,
        'check_updates' => CheckUpdatesAction::class,
    ];

    /** @var array<string, class-string> */
    private const POST_ACTIONS = [
        'save_settings' => SaveSettingsAction::class,
        'logout' => LogoutAction::class,
        'totp_begin' => TotpBeginAction::class,
        'totp_confirm' => TotpConfirmAction::class,
        'start' => StartAction::class,
        'stop' => StopAction::class,
        'restart' => RestartAction::class,
        'rename' => RenameAction::class,
        'add_runner' => AddRunnerAction::class,
        'delete_runner' => DeleteRunnerAction::class,
        'start_all' => StartAllAction::class,
        'stop_all' => StopAllAction::class,
        'bulk_start' => BulkStartAction::class,
        'bulk_stop' => BulkStopAction::class,
        'bulk_delete' => BulkDeleteAction::class,
        'resize' => ResizeAction::class,
    ];

    public static function run(): never
    {
        header('Content-Type: application/json');

        $action = (string) ($_GET['action'] ?? '');
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($action === 'csrf_token' && $method === 'GET') {
            CsrfTokenAction::handle();
        }

        if (Auth::isEnabled() && !Auth::isLoggedIn()) {
            self::respond(['ok' => false, 'message' => 'authentication required'], 401);
        }

        if ($method === 'GET') {
            $class = self::GET_ACTIONS[$action] ?? null;
            if ($class !== null) {
                $class::handle();
            }
            self::respond(['ok' => false, 'message' => 'not found'], 404);
        }

        if ($method !== 'POST') {
            self::respond(['ok' => false, 'message' => 'not found'], 404);
        }

        if (!Csrf::verifyRequest()) {
            self::respond(
                ['ok' => false, 'message' => 'missing or invalid CSRF token — reload the page and try again'],
                403
            );
        }

        $class = self::POST_ACTIONS[$action] ?? null;
        if ($class !== null) {
            $class::handle();
        }

        self::respond(['ok' => false, 'message' => 'unknown action'], 404);
    }

    public static function respond(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    public static function force(): bool
    {
        return (($_POST['force'] ?? $_GET['force'] ?? '0') === '1');
    }

    public static function requireRunner(string $id): RunnerInfo
    {
        if (!RunnerPool::isKnownId($id)) {
            self::respond(['ok' => false, 'message' => "unknown runner: {$id}"], 404);
        }
        $runners = RunnerPool::discover(0);
        return $runners[$id];
    }

    /**
     * @return list<string>
     */
    public static function parseRunnerIds(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($id) => $id !== ''));
    }

    /** @return RunnerInfo[] keyed by id, from the comma-separated `runners` POST field */
    public static function requireRunners(): array
    {
        $ids = self::parseRunnerIds((string) ($_POST['runners'] ?? ''));
        if (!$ids) {
            self::respond(['ok' => false, 'message' => 'no runners selected'], 422);
        }

        $unknown = array_filter($ids, fn ($id) => !RunnerPool::isKnownId($id));
        if ($unknown) {
            self::respond(['ok' => false, 'message' => 'unknown runner(s): ' . implode(', ', $unknown)], 404);
        }

        $all = RunnerPool::discover(0);
        return array_map(fn ($id) => $all[$id], $ids);
    }

    public static function checkNotBusy(string $agentName, bool $force): ?array
    {
        if ($force) {
            return null;
        }
        [$known, $busy, $error] = Dashboard::isBusy($agentName);
        if (!$known) {
            return [
                'ok' => false,
                'busy_unknown' => true,
                'message' => "Could not verify job status via GitHub API ({$error}). Pass force=1 to override.",
            ];
        }
        if ($busy) {
            return [
                'ok' => false,
                'busy' => true,
                'message' => "{$agentName} is currently running a job. Pass force=1 to stop anyway.",
            ];
        }
        return null;
    }

    /** @param RunnerInfo[] $runners Fetches GitHub's busy state once for the whole selection, not per runner. */
    public static function checkNoneBusy(array $runners, bool $force): ?array
    {
        if ($force) {
            return null;
        }

        try {
            $ghRunners = GithubClient::listRunners();
        } catch (\RuntimeException $e) {
            return [
                'ok' => false,
                'busy_unknown' => true,
                'message' => 'Could not verify job status via GitHub API ('
                    . $e->getMessage() . '). Pass force=1 to override.',
            ];
        }

        $busyNames = [];
        foreach ($runners as $r) {
            if ($ghRunners[$r->agentName]['busy'] ?? false) {
                $busyNames[] = $r->agentName;
            }
        }
        if ($busyNames) {
            $names = implode(', ', $busyNames);
            return [
                'ok' => false,
                'busy' => true,
                'busy_runners' => $busyNames,
                'message' => "Busy runners would be interrupted: {$names}. Pass force=1 to stop anyway.",
            ];
        }
        return null;
    }

    /** @param RunnerInfo[] $runners */
    public static function markExplicitlyStopped(array $runners): void
    {
        foreach ($runners as $r) {
            CrashState::markExplicitlyStopped($r->id);
        }
    }
}
