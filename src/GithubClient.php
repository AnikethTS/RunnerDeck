<?php

declare(strict_types=1);

namespace RunnerDeck;

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
     * @return array<string, array{id: int, status: string, busy: bool, labels: string[]}> keyed by runner name
     * @throws \RuntimeException if the gh call fails
     */
    public static function listRunners(): array
    {
        self::ensureGhEnv();
        $path = self::accountBase() . '/actions/runners?per_page=100';
        $result = Shell::exec(
            [Config::ghBinary(), 'api', '--paginate', $path, '-q', '.runners[]'],
            30
        );
        if ($result['code'] !== 0) {
            self::fail('gh.list_runners', 'gh api call failed: ' . trim($result['stderr']), $result['stderr'], true);
        }

        try {
            return self::decodeRunnerList($result['stdout']);
        } catch (\RuntimeException $e) {
            self::fail('gh.list_runners', $e->getMessage(), $result['stdout'], true);
        }
    }

    /**
     * Accept a single GitHub page object, an array of runner objects, or
     * newline-delimited runner objects from `gh api --paginate -q '.runners[]'`.
     *
     * @return array<string, array{id: int, status: string, busy: bool, labels: string[]}>
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
     * @param array<int|string, mixed> $rows
     * @return array<string, array{id: int, status: string, busy: bool, labels: string[]}>
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
            ];
        }
        return $runners;
    }

    /** @throws \RuntimeException if the gh call fails */
    public static function deleteRunner(int $id): void
    {
        self::ensureGhEnv();
        $result = Shell::exec(
            [Config::ghBinary(), 'api', '-X', 'DELETE', self::accountBase() . "/actions/runners/{$id}"],
            15
        );
        if ($result['code'] !== 0) {
            self::fail(
                'gh.delete_runner',
                'failed to deregister runner: ' . trim($result['stderr']),
                $result['stderr']
            );
        }
    }

    public static function registrationToken(): string
    {
        self::ensureGhEnv();
        $result = Shell::exec(
            [
                Config::ghBinary(), 'api', '-X', 'POST',
                self::accountBase() . '/actions/runners/registration-token',
                '-q', '.token',
            ],
            15
        );
        $token = trim($result['stdout']);
        if ($result['code'] !== 0 || $token === '') {
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
        self::ensureGhEnv();
        $result = Shell::exec(
            [Config::ghBinary(), 'api', self::accountBase() . '/actions/runners/downloads'],
            15
        );
        if ($result['code'] !== 0) {
            self::fail(
                'gh.list_downloads',
                'failed to list runner downloads: ' . trim($result['stderr']),
                $result['stderr']
            );
        }
        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            self::fail(
                'gh.list_downloads',
                'gh api returned unexpected output for runner downloads',
                $result['stdout']
            );
        }
        return $decoded;
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
