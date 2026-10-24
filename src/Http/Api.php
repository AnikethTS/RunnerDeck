<?php

declare(strict_types=1);

namespace RunnerDeck\Http;

use RunnerDeck\Core\Auth;
use RunnerDeck\Core\Config;
use RunnerDeck\Core\Csrf;
use RunnerDeck\GitHub\GithubClient;
use RunnerDeck\Http\Action\AddRunnerAction;
use RunnerDeck\Http\Action\BulkDeleteAction;
use RunnerDeck\Http\Action\BulkDrainStopAction;
use RunnerDeck\Http\Action\BulkRollingRestartAction;
use RunnerDeck\Http\Action\BulkStartAction;
use RunnerDeck\Http\Action\BulkStopAction;
use RunnerDeck\Http\Action\CheckUpdatesAction;
use RunnerDeck\Http\Action\ClearApplogAction;
use RunnerDeck\Http\Action\ClearWorkAction;
use RunnerDeck\Http\Action\CsrfTokenAction;
use RunnerDeck\Http\Action\DeleteRunnerAction;
use RunnerDeck\Http\Action\DrainStopAction;
use RunnerDeck\Http\Action\DrainStopAllAction;
use RunnerDeck\Http\Action\ForceIdleAction;
use RunnerDeck\Http\Action\HistoryAction;
use RunnerDeck\Http\Action\LogAction;
use RunnerDeck\Http\Action\LoginAction;
use RunnerDeck\Http\Action\LogoutAction;
use RunnerDeck\Http\Action\MaintenanceAction;
use RunnerDeck\Http\Action\PauseAutoRestartAction;
use RunnerDeck\Http\Action\QueueAction;
use RunnerDeck\Http\Action\RemoveOrphanAction;
use RunnerDeck\Http\Action\RenameAction;
use RunnerDeck\Http\Action\ResizeAction;
use RunnerDeck\Http\Action\RestartAction;
use RunnerDeck\Http\Action\RollingRestartAction;
use RunnerDeck\Http\Action\RollingUpgradeAction;
use RunnerDeck\Http\Action\SafeRebootAction;
use RunnerDeck\Http\Action\SaveSettingsAction;
use RunnerDeck\Http\Action\StartAction;
use RunnerDeck\Http\Action\StartAllAction;
use RunnerDeck\Http\Action\StatusAction;
use RunnerDeck\Http\Action\StopAction;
use RunnerDeck\Http\Action\StopAllAction;
use RunnerDeck\Http\Action\SystemAction;
use RunnerDeck\Http\Action\TestWebhookAction;
use RunnerDeck\Http\Action\TotpBeginAction;
use RunnerDeck\Http\Action\TotpConfirmAction;
use RunnerDeck\Pool\CrashState;
use RunnerDeck\Pool\RunnerInfo;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\Ui\Dashboard;

final class Api
{
    /** @var array<string, class-string> */
    private const GET_ACTIONS = [
        'status' => StatusAction::class,
        'log' => LogAction::class,
        'history' => HistoryAction::class,
        'system' => SystemAction::class,
        'check_updates' => CheckUpdatesAction::class,
        'queue' => QueueAction::class,
    ];

    /** @var array<string, class-string> */
    private const POST_ACTIONS = [
        'save_settings' => SaveSettingsAction::class,
        'test_webhook' => TestWebhookAction::class,
        'logout' => LogoutAction::class,
        'totp_begin' => TotpBeginAction::class,
        'totp_confirm' => TotpConfirmAction::class,
        'start' => StartAction::class,
        'stop' => StopAction::class,
        'restart' => RestartAction::class,
        'rolling_restart' => RollingRestartAction::class,
        'rolling_upgrade' => RollingUpgradeAction::class,
        'maintenance' => MaintenanceAction::class,
        'safe_reboot' => SafeRebootAction::class,
        'force_idle' => ForceIdleAction::class,
        'pause_auto_restart' => PauseAutoRestartAction::class,
        'rename' => RenameAction::class,
        'remove_orphan' => RemoveOrphanAction::class,
        'add_runner' => AddRunnerAction::class,
        'delete_runner' => DeleteRunnerAction::class,
        'start_all' => StartAllAction::class,
        'stop_all' => StopAllAction::class,
        'drain_stop' => DrainStopAction::class,
        'drain_stop_all' => DrainStopAllAction::class,
        'bulk_start' => BulkStartAction::class,
        'bulk_stop' => BulkStopAction::class,
        'bulk_drain_stop' => BulkDrainStopAction::class,
        'bulk_rolling_restart' => BulkRollingRestartAction::class,
        'bulk_delete' => BulkDeleteAction::class,
        'resize' => ResizeAction::class,
        'clear_work' => ClearWorkAction::class,
        'clear_applog' => ClearApplogAction::class,
    ];

    public static function run(): never
    {
        header('Content-Type: application/json');

        $action = (string) ($_GET['action'] ?? '');
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($action === 'csrf_token' && $method === 'GET') {
            CsrfTokenAction::handle();
        }

        if ($action === 'login' && $method === 'POST') {
            LoginAction::handle();
        }

        Auth::rejectApiIfProtected();

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

    public static function drainTimeout(): int
    {
        $raw = (string) ($_POST['timeout'] ?? '');
        if (ctype_digit($raw) && (int) $raw >= 1) {
            return min(3600, (int) $raw);
        }
        return Config::drainTimeoutSeconds();
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
            $agentName = $r->agentName;
            if ($ghRunners[$agentName]['busy'] ?? false) {
                $busyNames[] = $agentName;
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
