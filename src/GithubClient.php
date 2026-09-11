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

    public static function authStatus(): GithubAuthStatus
    {
        self::ensureGhEnv();
        $gh = Config::ghBinary();
        $login = Shell::exec([$gh, 'auth', 'status'], 10);
        if ($login['code'] !== 0) {
            return new GithubAuthStatus(false, false, trim($login['stderr'] ?: $login['stdout']) ?: 'gh auth status failed');
        }

        $probe = Shell::exec([$gh, 'api', 'orgs/' . Config::org() . '/actions/runners'], 15);
        if ($probe['code'] !== 0) {
            return new GithubAuthStatus(true, false, 'Logged in, but cannot read org runners: ' . trim($probe['stderr']));
        }

        return new GithubAuthStatus(true, true, 'OK');
    }

    /**
     * @return array<string, array{status: string, busy: bool, labels: string[]}> keyed by runner name
     * @throws RuntimeException if the gh call fails
     */
    public static function listOrgRunners(): array
    {
        self::ensureGhEnv();
        $result = Shell::exec([Config::ghBinary(), 'api', 'orgs/' . Config::org() . '/actions/runners'], 15);
        if ($result['code'] !== 0) {
            throw new RuntimeException('gh api call failed: ' . trim($result['stderr']));
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('gh api returned unexpected output');
        }

        $runners = [];
        foreach ($decoded['runners'] ?? [] as $r) {
            $runners[$r['name']] = [
                'status' => $r['status'],
                'busy' => (bool) $r['busy'],
                'labels' => array_map(static fn($l) => $l['name'], $r['labels'] ?? []),
            ];
        }
        return $runners;
    }

    public static function registrationToken(): string
    {
        self::ensureGhEnv();
        $result = Shell::exec(
            [Config::ghBinary(), 'api', '-X', 'POST', 'orgs/' . Config::org() . '/actions/runners/registration-token', '-q', '.token'],
            15
        );
        $token = trim($result['stdout']);
        if ($result['code'] !== 0 || $token === '') {
            throw new RuntimeException('failed to obtain a runner registration token: ' . trim($result['stderr']));
        }
        return $token;
    }

    /** @return array<int, array{os: string, architecture: string, download_url: string, filename: string, sha256_checksum?: string}> */
    public static function listRunnerDownloads(): array
    {
        self::ensureGhEnv();
        $result = Shell::exec(
            [Config::ghBinary(), 'api', 'orgs/' . Config::org() . '/actions/runners/downloads'],
            15
        );
        if ($result['code'] !== 0) {
            throw new RuntimeException('failed to list runner downloads: ' . trim($result['stderr']));
        }
        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('gh api returned unexpected output for runner downloads');
        }
        return $decoded;
    }
}
