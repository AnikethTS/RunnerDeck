<?php

declare(strict_types=1);

final class GithubAuthStatus
{
    public function __construct(
        public readonly bool $loggedIn,
        public readonly bool $orgAccessOk,
        public readonly string $message,
    ) {
    }

    public function toArray(): array
    {
        return [
            'logged_in' => $this->loggedIn,
            'org_access_ok' => $this->orgAccessOk,
            'message' => $this->message,
        ];
    }
}

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
     * (bin/doctor.php). Dashboard::snapshot() does NOT use this — it already
     * calls listRunners() on every poll, so probing access here first would
     * mean hitting the same API endpoint twice per poll for no reason.
     */
    public static function authStatus(): GithubAuthStatus
    {
        $login = self::checkLogin();
        if (!$login['logged_in']) {
            return new GithubAuthStatus(false, false, $login['message']);
        }

        self::ensureGhEnv();
        $probe = Shell::exec([Config::ghBinary(), 'api', self::accountBase() . '/actions/runners'], 15);
        if ($probe['code'] !== 0) {
            $scopeLabel = Config::scope() === 'repo' ? 'repo' : 'org';
            $message = "Logged in, but cannot read {$scopeLabel} runners: " . trim($probe['stderr']);
            return new GithubAuthStatus(true, false, $message);
        }

        return new GithubAuthStatus(true, true, 'OK');
    }

    /**
     * @return array<string, array{id: int, status: string, busy: bool, labels: string[]}> keyed by runner name
     * @throws RuntimeException if the gh call fails
     */
    public static function listRunners(): array
    {
        self::ensureGhEnv();
        $result = Shell::exec([Config::ghBinary(), 'api', self::accountBase() . '/actions/runners'], 15);
        if ($result['code'] !== 0) {
            self::fail('gh.list_runners', 'gh api call failed: ' . trim($result['stderr']), $result['stderr'], true);
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            self::fail('gh.list_runners', 'gh api returned unexpected output', $result['stdout'], true);
        }

        $runners = [];
        foreach ($decoded['runners'] ?? [] as $r) {
            $runners[$r['name']] = [
                'id' => (int) $r['id'],
                'status' => $r['status'],
                'busy' => (bool) $r['busy'],
                'labels' => array_map(static fn($l) => $l['name'], $r['labels'] ?? []),
            ];
        }
        return $runners;
    }

    /** @throws RuntimeException if the gh call fails */
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

    /** @throws RuntimeException */
    private static function fail(string $action, string $message, string $stderr = '', bool $throttle = false): never
    {
        $ctx = ['stderr' => $stderr];
        if ($throttle) {
            AppLog::errorThrottled($action, $message, $ctx);
        } else {
            AppLog::error($action, $message, $ctx);
        }
        throw new RuntimeException($message);
    }
}
