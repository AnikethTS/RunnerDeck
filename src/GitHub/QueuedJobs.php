<?php

declare(strict_types=1);

namespace RunnerDeck\GitHub;

use RunnerDeck\Alert\LongJobAlert;
use RunnerDeck\Alert\QueueAlert;
use RunnerDeck\Core\Config;
use RunnerDeck\Core\Settings;
use RunnerDeck\Pool\RunnerPool;

final class QueuedJobs
{
    private const CACHE_FILE = 'queue_cache.json';
    private const CACHE_TTL = 25;
    private const MAX_REPOS = 5;
    private const MAX_RUNS = 8;
    private const MAX_JOBS = 20;

    /**
     * @return array{
     *     ok: bool,
     *     label: string,
     *     count: int,
     *     truncated: bool,
     *     hint: string,
     *     hint_action: string,
     *     jobs: list<array<string, mixed>>,
     *     live: list<array<string, mixed>>,
     *     recent: list<array<string, mixed>>,
     *     message: string
     * }
     */
    public static function snapshot(): array
    {
        $cached = self::readCache();
        if ($cached !== null) {
            return self::withHint($cached);
        }

        try {
            $payload = self::fetch();
        } catch (\RuntimeException $e) {
            $payload = [
                'ok' => false,
                'label' => Config::label(),
                'count' => 0,
                'truncated' => false,
                'jobs' => [],
                'live' => [],
                'recent' => [],
                'message' => $e->getMessage(),
            ];
        }
        self::writeCache($payload);
        return self::withHint($payload);
    }

    /**
     * @param array{
     *     ok?: bool,
     *     label?: string,
     *     count?: int,
     *     truncated?: bool,
     *     jobs?: list<array<string, mixed>>,
     *     live?: list<array<string, mixed>>,
     *     recent?: list<array<string, mixed>>,
     *     message?: string
     * } $payload
     * @return array{
     *     ok: bool,
     *     label: string,
     *     count: int,
     *     truncated: bool,
     *     hint: string,
     *     hint_action: string,
     *     jobs: list<array<string, mixed>>,
     *     live: list<array<string, mixed>>,
     *     recent: list<array<string, mixed>>,
     *     message: string
     * }
     */
    public static function withHint(array $payload): array
    {
        $stopped = 0;
        $configured = 0;
        foreach (RunnerPool::discover(0) as $r) {
            if (!$r->configured) {
                continue;
            }
            $configured++;
            if (!$r->localRunning) {
                $stopped++;
            }
        }
        $count = (int) ($payload['count'] ?? 0);
        $running = $configured - $stopped;
        $hint = self::hintFor($count, $stopped, $configured, Config::poolCap(), $running);
        $out = [
            'ok' => (bool) ($payload['ok'] ?? false),
            'label' => (string) ($payload['label'] ?? Config::label()),
            'count' => $count,
            'truncated' => (bool) ($payload['truncated'] ?? false),
            'hint' => $hint['hint'],
            'hint_action' => $hint['hint_action'],
            'jobs' => is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [],
            'live' => is_array($payload['live'] ?? null) ? $payload['live'] : [],
            'recent' => is_array($payload['recent'] ?? null) ? $payload['recent'] : [],
            'message' => (string) ($payload['message'] ?? ''),
        ];
        QueueAlert::check($out['jobs']);
        LongJobAlert::check($out['live']);
        return $out;
    }

    /**
     * @return array{hint: string, hint_action: string}
     */
    public static function hintFor(
        int $queued,
        int $stoppedConfigured,
        int $configured,
        ?int $cap = null,
        ?int $running = null
    ): array {
        if ($queued < 1) {
            return ['hint' => '', 'hint_action' => ''];
        }
        if ($stoppedConfigured > 0) {
            return [
                'hint' => $stoppedConfigured . ' stopped slot(s) can pick these up.',
                'hint_action' => 'start',
            ];
        }
        $running = $running ?? max(0, $configured - $stoppedConfigured);
        if ($cap !== null && $running >= $cap) {
            return [
                'hint' => 'Pool cap is ' . $cap . ' running slot(s). Jobs will wait.',
                'hint_action' => '',
            ];
        }
        if ($configured < 30) {
            return [
                'hint' => 'All slots are busy. Add a runner if this host can take another.',
                'hint_action' => 'add',
            ];
        }
        return [
            'hint' => 'Pool is at max size and busy. Jobs will wait.',
            'hint_action' => '',
        ];
    }

    /**
     * @param list<string> $labels
     */
    public static function jobWantsLabel(array $labels, string $label): bool
    {
        return $label !== '' && in_array($label, $labels, true);
    }

    /**
     * @param array<int|string, mixed> $jobsPayload
     * @return list<array<string, mixed>>
     */
    public static function pickQueuedJobs(array $jobsPayload, string $repo, string $label): array
    {
        $out = [];
        $jobs = $jobsPayload['jobs'] ?? [];
        if (!is_array($jobs)) {
            return [];
        }
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }
            $status = (string) ($job['status'] ?? '');
            if (!in_array($status, ['queued', 'waiting', 'pending', 'requested'], true)) {
                continue;
            }
            $labels = [];
            foreach ($job['labels'] ?? [] as $item) {
                if (is_string($item) && $item !== '') {
                    $labels[] = $item;
                }
            }
            if (!self::jobWantsLabel($labels, $label)) {
                continue;
            }
            $created = strtotime((string) ($job['created_at'] ?? '')) ?: 0;
            $waitedSec = $created > 0 ? max(0, time() - $created) : 0;
            $out[] = [
                'id' => (int) ($job['id'] ?? 0),
                'name' => (string) ($job['name'] ?? 'job'),
                'workflow' => (string) ($job['workflow_name'] ?? ''),
                'repo' => $repo,
                'html_url' => (string) ($job['html_url'] ?? ''),
                'status' => $status,
                'waited' => self::formatWait($waitedSec),
                'waited_sec' => $waitedSec,
            ];
        }
        return $out;
    }

    /**
     * In-progress jobs assigned to a named runner (busy-row overlay).
     *
     * @param array<int|string, mixed> $jobsPayload
     * @return list<array<string, mixed>>
     */
    public static function pickLiveJobs(array $jobsPayload, string $repo): array
    {
        $out = [];
        $jobs = $jobsPayload['jobs'] ?? [];
        if (!is_array($jobs)) {
            return [];
        }
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }
            if ((string) ($job['status'] ?? '') !== 'in_progress') {
                continue;
            }
            $runner = (string) ($job['runner_name'] ?? '');
            if ($runner === '') {
                continue;
            }
            $started = strtotime((string) ($job['started_at'] ?? '')) ?: 0;
            $durationSec = $started > 0 ? max(0, time() - $started) : 0;
            $out[] = [
                'id' => (int) ($job['id'] ?? 0),
                'name' => (string) ($job['name'] ?? 'job'),
                'workflow' => (string) ($job['workflow_name'] ?? ''),
                'repo' => $repo,
                'html_url' => (string) ($job['html_url'] ?? ''),
                'runner_name' => $runner,
                'started_at' => $started > 0 ? gmdate('c', $started) : '',
                'duration_sec' => $durationSec,
                'duration' => $durationSec > 0 ? self::formatWait($durationSec) : '',
            ];
        }
        return $out;
    }

    /**
     * Recently completed jobs that ran on a named runner.
     *
     * @param array<int|string, mixed> $jobsPayload
     * @return list<array<string, mixed>>
     */
    public static function pickRecentJobs(array $jobsPayload, string $repo): array
    {
        $out = [];
        $jobs = $jobsPayload['jobs'] ?? [];
        if (!is_array($jobs)) {
            return [];
        }
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }
            if ((string) ($job['status'] ?? '') !== 'completed') {
                continue;
            }
            $runner = (string) ($job['runner_name'] ?? '');
            if ($runner === '') {
                continue;
            }
            $started = strtotime((string) ($job['started_at'] ?? '')) ?: 0;
            $completed = strtotime((string) ($job['completed_at'] ?? '')) ?: 0;
            $duration = ($started > 0 && $completed >= $started) ? ($completed - $started) : 0;
            $out[] = [
                'id' => (int) ($job['id'] ?? 0),
                'name' => (string) ($job['name'] ?? 'job'),
                'workflow' => (string) ($job['workflow_name'] ?? ''),
                'repo' => $repo,
                'html_url' => (string) ($job['html_url'] ?? ''),
                'runner_name' => $runner,
                'conclusion' => (string) ($job['conclusion'] ?? ''),
                'duration' => $duration > 0 ? self::formatWait($duration) : '',
                'completed_at' => $completed,
            ];
        }
        return $out;
    }

    public static function formatWait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm';
        }
        return intdiv($seconds, 3600) . 'h';
    }

    /**
     * @return array{
     *     ok: bool,
     *     label: string,
     *     count: int,
     *     truncated: bool,
     *     jobs: list<array<string, mixed>>,
     *     live: list<array<string, mixed>>,
     *     recent: list<array<string, mixed>>,
     *     message: string
     * }
     */
    private static function fetch(): array
    {
        $label = Config::label();
        $empty = [
            'ok' => true,
            'label' => $label,
            'count' => 0,
            'truncated' => false,
            'jobs' => [],
            'live' => [],
            'recent' => [],
            'message' => '',
        ];
        $login = GithubClient::checkLogin();
        if (!$login['logged_in']) {
            $empty['message'] = 'gh CLI is not authenticated';
            return $empty;
        }

        $repos = self::reposToScan();
        $jobs = [];
        $live = [];
        $recent = [];
        $seenRuns = [];
        $truncated = count($repos) >= self::MAX_REPOS;
        foreach ($repos as $repo) {
            $runs = self::runsForRepo($repo);
            if (count($runs) >= self::MAX_RUNS) {
                $truncated = true;
            }
            foreach ($runs as $runId) {
                $seenRuns[$repo . '#' . $runId] = true;
                $payload = self::tryGetJson(
                    'repos/' . $repo . '/actions/runs/' . $runId . '/jobs?per_page=50'
                );
                if ($payload === null) {
                    continue;
                }
                self::appendLive($live, self::pickLiveJobs($payload, $repo));
                foreach (self::pickRecentJobs($payload, $repo) as $job) {
                    if (count($recent) < 30) {
                        $recent[] = $job;
                    }
                }
                foreach (self::pickQueuedJobs($payload, $repo, $label) as $job) {
                    $jobs[] = $job;
                    if (count($jobs) >= self::MAX_JOBS) {
                        self::appendOrgInProgress($live, $seenRuns);
                        return [
                            'ok' => true,
                            'label' => $label,
                            'count' => count($jobs),
                            'truncated' => true,
                            'jobs' => $jobs,
                            'live' => $live,
                            'recent' => $recent,
                            'message' => '',
                        ];
                    }
                }
            }
        }

        self::appendOrgInProgress($live, $seenRuns);

        return [
            'ok' => true,
            'label' => $label,
            'count' => count($jobs),
            'truncated' => $truncated,
            'jobs' => $jobs,
            'live' => $live,
            'recent' => $recent,
            'message' => '',
        ];
    }

    /**
     * Org scope has no waiting-jobs list, and the queued scan only walks a
     * few recently pushed repos. In-progress runs are listed on the org so a
     * busy row still gets its job when that repo was not in the scan.
     *
     * @param list<array<string, mixed>> $live
     * @param array<string, true> $seenRuns
     */
    private static function appendOrgInProgress(array &$live, array $seenRuns): void
    {
        if (Config::scope() !== 'org') {
            return;
        }
        $org = Config::org();
        $payload = self::tryGetJson(
            'orgs/' . $org . '/actions/runs?status=in_progress&per_page=20'
        );
        if ($payload === null) {
            return;
        }
        $runs = $payload['workflow_runs'] ?? [];
        if (!is_array($runs)) {
            return;
        }
        $n = 0;
        foreach ($runs as $run) {
            if (!is_array($run) || $n >= 20) {
                break;
            }
            $n++;
            $repo = '';
            $repository = $run['repository'] ?? null;
            if (is_array($repository)) {
                $repo = (string) ($repository['full_name'] ?? '');
            }
            $runId = (int) ($run['id'] ?? 0);
            if ($repo === '' || !str_contains($repo, '/') || $runId < 1) {
                continue;
            }
            if (isset($seenRuns[$repo . '#' . $runId])) {
                continue;
            }
            $jobsPayload = self::tryGetJson(
                'repos/' . $repo . '/actions/runs/' . $runId . '/jobs?per_page=50'
            );
            if ($jobsPayload === null) {
                continue;
            }
            $picked = self::pickLiveJobs($jobsPayload, $repo);
            $runUrl = (string) ($run['html_url'] ?? '');
            if ($runUrl !== '') {
                foreach ($picked as $i => $job) {
                    if (($job['html_url'] ?? '') === '') {
                        $picked[$i]['html_url'] = $runUrl;
                    }
                }
            }
            self::appendLive($live, $picked);
        }
    }

    /**
     * @param list<array<string, mixed>> $live
     * @param list<array<string, mixed>> $jobs
     */
    private static function appendLive(array &$live, array $jobs): void
    {
        $seen = [];
        foreach ($live as $job) {
            $seen[(int) ($job['id'] ?? 0)] = true;
        }
        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            if ($id > 0 && isset($seen[$id])) {
                continue;
            }
            if (count($live) >= 30) {
                return;
            }
            $live[] = $job;
            if ($id > 0) {
                $seen[$id] = true;
            }
        }
    }

    /** @return list<string> owner/repo */
    private static function reposToScan(): array
    {
        if (Config::scope() === 'repo') {
            return [Config::repo()];
        }
        $payload = self::tryGetJson(
            'orgs/' . Config::org() . '/repos?sort=pushed&per_page=' . self::MAX_REPOS . '&type=all'
        );
        if ($payload === null) {
            return [];
        }
        $rows = array_is_list($payload) ? $payload : [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['archived']) || !empty($row['disabled'])) {
                continue;
            }
            $name = (string) ($row['full_name'] ?? '');
            if ($name !== '' && str_contains($name, '/')) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @return list<int> */
    private static function runsForRepo(string $repo): array
    {
        $ids = [];
        foreach (['queued', 'in_progress', 'completed'] as $status) {
            $payload = self::tryGetJson(
                'repos/' . $repo . '/actions/runs?status=' . $status . '&per_page=' . self::MAX_RUNS
            );
            if ($payload === null) {
                continue;
            }
            $runs = $payload['workflow_runs'] ?? [];
            if (!is_array($runs)) {
                continue;
            }
            foreach ($runs as $run) {
                if (!is_array($run)) {
                    continue;
                }
                $id = (int) ($run['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    /** Queued job count from the last queue poll cache. Does not call GitHub. */
    public static function peekCachedCount(): int
    {
        $payload = self::peekCachePayload();
        if ($payload === null) {
            return 0;
        }
        return max(0, (int) ($payload['count'] ?? 0));
    }

    /**
     * Completed jobs from the last queue poll, even if the cache TTL has
     * passed. Empty when the queue action has not run yet. Does not call GitHub.
     *
     * @return list<array<string, mixed>>
     */
    public static function peekRecent(): array
    {
        $payload = self::peekCachePayload();
        if ($payload === null) {
            return [];
        }
        $recent = $payload['recent'] ?? [];
        if (!is_array($recent)) {
            return [];
        }
        $out = [];
        foreach ($recent as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function peekCachePayload(): ?array
    {
        $path = self::cachePath();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $payload = $decoded['payload'] ?? null;
        return is_array($payload) ? $payload : null;
    }

    /** @return array<string, mixed>|null */
    private static function readCache(): ?array
    {
        $path = self::cachePath();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $at = (int) ($decoded['fetched_at'] ?? 0);
        if ($at < 1 || (time() - $at) > self::CACHE_TTL) {
            return null;
        }
        $payload = $decoded['payload'] ?? null;
        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $payload */
    private static function writeCache(array $payload): void
    {
        $dir = dirname(self::cachePath());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = self::cachePath() . '.tmp';
        file_put_contents($tmp, json_encode([
            'fetched_at' => time(),
            'payload' => $payload,
        ], JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, self::cachePath());
    }

    private static function cachePath(): string
    {
        return Settings::storageDir() . '/' . self::CACHE_FILE;
    }

    /** @return array<int|string, mixed>|null */
    private static function tryGetJson(string $path): ?array
    {
        try {
            return GithubClient::getJson($path);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
