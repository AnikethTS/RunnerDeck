<?php

declare(strict_types=1);

namespace RunnerDeck\GitHub;

use RunnerDeck\Core\AppLog;
use RunnerDeck\Core\Config;
use RunnerDeck\Core\Shell;

final class GithubClient
{
    private static function ensureGhEnv(): void
    {
        static $applied = false;
        if ($applied) {
            return;
        }
        $applied = true;
        if ($dir = Config::ghConfigDir()) {
            putenv("GH_CONFIG_DIR=$dir");
        }
        GithubApp::applyToEnv();
    }

    /** 'orgs/{org}' or 'repos/{owner}/{repo}' depending on Config::scope() — the prefix for every runners API call */
    private static function accountBase(): string
    {
        return Config::scope() === 'repo' ? 'repos/' . Config::repo() : 'orgs/' . Config::org();
    }

    /** @return array{logged_in: bool, message: string} just `gh auth status` — no API call */
    public static function checkLogin(): array
    {
        self::ensureGhEnv();
        if (GithubApp::isConfigured()) {
            if (GithubApp::token() !== null) {
                return ['logged_in' => true, 'message' => 'OK'];
            }
            return [
                'logged_in' => false,
                'message' => 'GitHub App is set but the installation token could not be minted',
            ];
        }
        $login = Shell::exec([Config::ghBinary(), 'auth', 'status'], 10);
        if ($login['code'] !== 0) {
            $message = trim($login['stderr'] ?: $login['stdout']) ?: 'gh auth status failed';
            return ['logged_in' => false, 'message' => $message];
        }
        return ['logged_in' => true, 'message' => 'OK'];
    }

    /**
     * A standalone login + org/repo-access health check for one-shot callers
     * (bin/doctor.php). Dashboard::snapshot() does NOT use this — on the
     * happy path it only calls listRunners(), and falls back to checkLogin()
     * when that fails so the health banner can tell "not logged in" apart
     * from "logged in but cannot read runners."
     */
    public static function authStatus(): GithubAuthStatus
    {
        $login = self::checkLogin();
        if (!$login['logged_in']) {
            return new GithubAuthStatus(false, false, $login['message']);
        }

        try {
            self::listRunners();
        } catch (\RuntimeException $e) {
            $scopeLabel = Config::scope() === 'repo' ? 'repo' : 'org';
            $message = "Logged in, but cannot read {$scopeLabel} runners: " . $e->getMessage();
            return new GithubAuthStatus(true, false, $message);
        }

        return new GithubAuthStatus(true, true, 'OK');
    }

    /**
     * @return array<string, array{id: int, status: string, busy: bool, labels: string[], os: string}>
     *     keyed by runner name
     * @throws \RuntimeException if the gh call fails
     */
    public static function listRunners(): array
    {
        $path = self::accountBase() . '/actions/runners?per_page=100';
        $result = self::runApi(['--paginate', $path, '-q', '.runners[]'], 30);
        if (!$result['ok']) {
            self::fail('gh.list_runners', 'gh api call failed: ' . trim($result['stderr']), $result['stderr'], true);
        }

        try {
            return self::decodeRunnerList($result['body']);
        } catch (\RuntimeException $e) {
            self::fail('gh.list_runners', $e->getMessage(), $result['body'], true);
        }
    }

    /**
     * Accept a single GitHub page object, an array of runner objects, or
     * newline-delimited runner objects from `gh api --paginate -q '.runners[]'`.
     *
     * @return array<string, array{id: int, status: string, busy: bool, labels: string[], os: string}>
     */
    public static function decodeRunnerList(string $stdout): array
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            if (isset($decoded['runners']) && is_array($decoded['runners'])) {
                return self::indexRunners($decoded['runners']);
            }
            if ($decoded !== [] && array_is_list($decoded) && isset($decoded[0]['name'])) {
                return self::indexRunners($decoded);
            }
        }

        $runners = [];
        foreach (preg_split('/\r?\n/', $trimmed) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                throw new \RuntimeException('gh api returned unexpected output');
            }
            if (isset($row['runners']) && is_array($row['runners'])) {
                $runners = array_merge($runners, self::indexRunners($row['runners']));
                continue;
            }
            if (isset($row['name'])) {
                $runners = array_merge($runners, self::indexRunners([$row]));
                continue;
            }
            throw new \RuntimeException('gh api returned unexpected output');
        }

        return $runners;
    }

    /**
     * GET a GitHub REST path via `gh api`. Used for workflow-run/job queue
     * polling — not the 5s runner snapshot.
     *
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public static function getJson(string $path, int $timeout = 15): array
    {
        $result = self::runApi([$path], $timeout);
        if (!$result['ok']) {
            self::fail(
                'gh.api',
                'gh api call failed: ' . trim($result['stderr']),
                $result['stderr'],
                true
            );
        }
        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            self::fail('gh.api', 'gh api returned unexpected output', $result['body'], true);
        }
        return $decoded;
    }

    /**
     * Split `gh api --include` stdout into HTTP Date (if any) and JSON body.
     * Output without an HTTP envelope is returned unchanged (fail-open).
     *
     * @return array{date: ?string, body: string}
     */
    public static function parseGhOutput(string $raw): array
    {
        $text = str_replace("\r\n", "\n", $raw);
        if (!str_starts_with(ltrim($text), 'HTTP/')) {
            return ['date' => null, 'body' => $raw];
        }
        $date = null;
        $bodies = [];
        foreach (preg_split('/(?=^HTTP\/)/m', $text) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $parts = preg_split('/\n\n/', $chunk, 2);
            $headers = $parts[0] ?? '';
            $bodies[] = $parts[1] ?? '';
            if ($date === null && preg_match('/^Date:\s*(.+)$/mi', $headers, $m) === 1) {
                $date = trim($m[1]);
            }
        }
        return ['date' => $date, 'body' => implode("\n", $bodies)];
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return array<string, array{id: int, status: string, busy: bool, labels: string[], os: string}>
     */
    private static function indexRunners(array $rows): array
    {
        $runners = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['name']) || !is_string($r['name']) || $r['name'] === '') {
                continue;
            }
            $labels = [];
            foreach ($r['labels'] ?? [] as $label) {
                if (is_array($label) && isset($label['name']) && is_string($label['name'])) {
                    $labels[] = $label['name'];
                } elseif (is_string($label)) {
                    $labels[] = $label;
                }
            }
            $runners[$r['name']] = [
                'id' => (int) ($r['id'] ?? 0),
                'status' => is_string($r['status'] ?? null) ? $r['status'] : '',
                'busy' => (bool) ($r['busy'] ?? false),
                'labels' => $labels,
                'os' => is_string($r['os'] ?? null) ? $r['os'] : '',
            ];
        }
        return $runners;
    }

    /** @throws \RuntimeException if the gh call fails */
    public static function deleteRunner(int $id): void
    {
        $result = self::runApi(
            ['-X', 'DELETE', self::accountBase() . "/actions/runners/{$id}"],
            15,
            false
        );
        if (!$result['ok']) {
            self::fail(
                'gh.delete_runner',
                'failed to deregister runner: ' . trim($result['stderr']),
                $result['stderr']
            );
        }
    }

    public static function registrationToken(): string
    {
        $result = self::runApi(
            ['-X', 'POST', self::accountBase() . '/actions/runners/registration-token', '-q', '.token'],
            15,
            false
        );
        $token = trim($result['body']);
        if (!$result['ok'] || $token === '') {
            self::fail(
                'gh.registration_token',
                'failed to obtain a runner registration token: ' . trim($result['stderr']),
                $result['stderr']
            );
        }
        return $token;
    }

    /**
     * @return array<int, array{
     *     os: string,
     *     architecture: string,
     *     download_url: string,
     *     filename: string,
     *     sha256_checksum?: string
     * }>
     */
    public static function listRunnerDownloads(): array
    {
        $result = self::runApi([self::accountBase() . '/actions/runners/downloads'], 15);
        if (!$result['ok']) {
            self::fail(
                'gh.list_downloads',
                'failed to list runner downloads: ' . trim($result['stderr']),
                $result['stderr']
            );
        }
        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            self::fail(
                'gh.list_downloads',
                'gh api returned unexpected output for runner downloads',
                $result['body']
            );
        }
        return $decoded;
    }

    /**
     * @param list<string> $ghArgs arguments after `gh api --include`
     * @return array{ok: bool, body: string, stderr: string}
     */
    private static function runApi(array $ghArgs, int $timeout, bool $observeClock = true): array
    {
        self::ensureGhEnv();
        GithubBackoff::throwIfCooling();
        $cmd = array_merge([Config::ghBinary(), 'api', '--include'], $ghArgs);
        $result = Shell::exec($cmd, $timeout);
        $parsed = self::parseGhOutput($result['stdout']);
        if ($observeClock) {
            GithubClock::observeHttpDate($parsed['date']);
        }
        $blob = $result['stderr'] . "\n" . $result['stdout'];
        if ($result['code'] !== 0) {
            GithubBackoff::noteFailure($blob);
            return ['ok' => false, 'body' => $parsed['body'], 'stderr' => $result['stderr']];
        }
        GithubBackoff::clear();
        return ['ok' => true, 'body' => $parsed['body'], 'stderr' => $result['stderr']];
    }

    /** @throws \RuntimeException */
    private static function fail(string $action, string $message, string $stderr = '', bool $throttle = false): never
    {
        $ctx = ['stderr' => $stderr];
        if ($throttle) {
            AppLog::errorThrottled($action, $message, $ctx);
        } else {
            AppLog::error($action, $message, $ctx);
        }
        throw new \RuntimeException($message);
    }
}
