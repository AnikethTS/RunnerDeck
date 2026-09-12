<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function requireRunner(string $id): RunnerInfo
{
    if (!RunnerPool::isKnownId($id)) {
        respond(['ok' => false, 'message' => "unknown runner: {$id}"], 404);
    }
    $runners = RunnerPool::discover(0);
    return $runners[$id];
}

function checkNotBusy(string $agentName, bool $force): ?array
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

/** @return RunnerInfo[] keyed by id, from the comma-separated `runners` POST field */
function requireRunners(): array
{
    $raw = (string) ($_POST['runners'] ?? '');
    $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($id) => $id !== ''));
    if (!$ids) {
        respond(['ok' => false, 'message' => 'no runners selected'], 422);
    }

    $unknown = array_filter($ids, fn ($id) => !RunnerPool::isKnownId($id));
    if ($unknown) {
        respond(['ok' => false, 'message' => 'unknown runner(s): ' . implode(', ', $unknown)], 404);
    }

    $all = RunnerPool::discover(0);
    return array_map(fn ($id) => $all[$id], $ids);
}

/** @param RunnerInfo[] $runners Fetches GitHub's busy state once for the whole selection, not per runner. */
function checkNoneBusy(array $runners, bool $force): ?array
{
    if ($force) {
        return null;
    }

    try {
        $ghRunners = GithubClient::listRunners();
    } catch (RuntimeException $e) {
        return [
            'ok' => false,
            'busy_unknown' => true,
            'message' => "Could not verify job status via GitHub API ({$e->getMessage()}). Pass force=1 to override.",
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

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'status' && $method === 'GET') {
    if (!Config::isConfigured()) {
        respond(['ok' => false, 'message' => 'RunnerDeck is not configured yet'], 409);
    }
    $lines = max(0, min(500, (int) ($_GET['lines'] ?? 15)));
    respond(Dashboard::snapshot($lines));
}

if ($action === 'log' && $method === 'GET') {
    $id = $_GET['runner'] ?? '';
    if (!RunnerPool::isKnownId($id)) {
        respond(['ok' => false, 'message' => "unknown runner: {$id}"], 404);
    }
    $lines = max(1, min(2000, (int) ($_GET['lines'] ?? 200)));
    $tail = RunnerPool::tailLog(RunnerPool::dirFor($id) . '/runner.log', $lines);
    respond(['ok' => true, 'lines' => $tail]);
}

if ($action === 'history' && $method === 'GET') {
    respond(['ok' => true, 'available' => History::isAvailable(), 'samples' => History::recent()]);
}

if ($action === 'system' && $method === 'GET') {
    respond(['ok' => true, 'system' => SystemStats::snapshot()]);
}

if ($action === 'check_updates' && $method === 'GET') {
    if (!Config::checkUpdatesEnabled()) {
        respond(['ok' => false, 'message' => 'update checks are disabled'], 403);
    }
    respond(['ok' => true] + UpdateCheck::check());
}

if ($action === 'csrf_token' && $method === 'GET') {
    respond(['ok' => true, 'token' => Csrf::token()]);
}

if ($method !== 'POST') {
    respond(['ok' => false, 'message' => 'not found'], 404);
}

if (!Csrf::verifyRequest()) {
    respond(['ok' => false, 'message' => 'missing or invalid CSRF token — reload the page and try again'], 403);
}

if ($action === 'save_settings') {
    $scope = $_POST['scope'] ?? '';
    if ($scope !== 'org' && $scope !== 'repo') {
        respond(['ok' => false, 'message' => "scope must be 'org' or 'repo'"], 422);
    }

    $org = trim((string) ($_POST['org'] ?? ''));
    $repo = trim((string) ($_POST['repo'] ?? ''));
    $label = trim((string) ($_POST['label'] ?? ''));

    if ($scope === 'org' && $org === '') {
        respond(['ok' => false, 'message' => 'org is required for org scope'], 422);
    }
    if ($scope === 'repo' && !str_contains($repo, '/')) {
        respond(['ok' => false, 'message' => "repo must be in 'owner/repo' format"], 422);
    }

    Settings::save([
        'RUNNERDECK_SCOPE' => $scope,
        'RUNNERDECK_ORG' => $scope === 'org' ? $org : '',
        'RUNNERDECK_REPO' => $scope === 'repo' ? $repo : '',
        'RUNNERDECK_LABEL' => $label,
        'RUNNERDECK_CHECK_UPDATES' => ($_POST['check_updates'] ?? '') === '1' ? '1' : '',
    ]);

    respond(['ok' => true, 'message' => 'settings saved']);
}

$force = (($_POST['force'] ?? $_GET['force'] ?? '0') === '1');

if ($action === 'start') {
    $r = requireRunner($_POST['runner'] ?? '');
    respond(ProcessControl::startIndividual($r));
}

if ($action === 'stop') {
    $r = requireRunner($_POST['runner'] ?? '');
    if ($blocked = checkNotBusy($r->agentName, $force)) {
        respond($blocked, 409);
    }
    respond(ProcessControl::stopIndividual($r));
}

if ($action === 'restart') {
    $r = requireRunner($_POST['runner'] ?? '');
    if ($blocked = checkNotBusy($r->agentName, $force)) {
        respond($blocked, 409);
    }
    respond(ProcessControl::restartIndividual($r));
}

if ($action === 'rename') {
    $r = requireRunner($_POST['runner'] ?? '');
    $newName = trim((string) ($_POST['name'] ?? ''));
    if ($newName === '') {
        respond(['ok' => false, 'message' => 'name is required'], 422);
    }
    if ($blocked = checkNotBusy($r->agentName, $force)) {
        respond($blocked, 409);
    }
    respond(ProcessControl::renameRunner($r, $newName));
}

if ($action === 'add_runner') {
    $name = trim((string) ($_POST['name'] ?? ''));
    respond(ProcessControl::addRunner($name !== '' ? $name : null));
}

if ($action === 'delete_runner') {
    $r = requireRunner($_POST['runner'] ?? '');
    if ($blocked = checkNotBusy($r->agentName, $force)) {
        respond($blocked, 409);
    }
    respond(ProcessControl::deleteRunner($r));
}

if ($action === 'start_all') {
    $count = max(1, min(30, (int) ($_POST['count'] ?? 10)));
    respond(ProcessControl::startAll($count));
}

if ($action === 'stop_all') {
    if ($blocked = checkNoneBusy(RunnerPool::discover(0), $force)) {
        respond($blocked, 409);
    }
    respond(ProcessControl::stopAll());
}

if ($action === 'bulk_start') {
    respond(ProcessControl::bulkStart(requireRunners()));
}

if ($action === 'bulk_stop') {
    $runners = requireRunners();
    if ($blocked = checkNoneBusy($runners, $force)) {
        respond($blocked, 409);
    }
    respond(ProcessControl::bulkStop($runners));
}

if ($action === 'bulk_delete') {
    $runners = requireRunners();
    if ($blocked = checkNoneBusy($runners, $force)) {
        respond($blocked, 409);
    }
    respond(ProcessControl::bulkDelete($runners));
}

if ($action === 'resize') {
    $count = max(1, min(30, (int) ($_POST['count'] ?? 10)));
    respond(ProcessControl::resizePool($count));
}

respond(['ok' => false, 'message' => 'unknown action'], 404);
