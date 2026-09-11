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

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'status' && $method === 'GET') {
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
    respond(['ok' => true, 'samples' => History::recent()]);
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

if ($action === 'start_all') {
    $count = max(1, min(30, (int) ($_POST['count'] ?? 10)));
    respond(ProcessControl::startAll($count));
}

if ($action === 'stop_all') {
    if (!$force) {
        try {
            $busyNames = [];
            foreach (GithubClient::listRunners() as $name => $info) {
                if ($info['busy']) {
                    $busyNames[] = $name;
                }
            }
            if ($busyNames) {
                $names = implode(', ', $busyNames);
                respond([
                    'ok' => false,
                    'busy' => true,
                    'busy_runners' => $busyNames,
                    'message' => "Busy runners would be interrupted: {$names}. Pass force=1 to stop anyway.",
                ], 409);
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            respond([
                'ok' => false,
                'busy_unknown' => true,
                'message' => "Could not verify job status via GitHub API ({$error}). Pass force=1 to override.",
            ], 409);
        }
    }
    respond(ProcessControl::stopAll());
}

if ($action === 'resize') {
    $count = max(1, min(30, (int) ($_POST['count'] ?? 10)));
    respond(ProcessControl::resizePool($count));
}

respond(['ok' => false, 'message' => 'unknown action'], 404);
