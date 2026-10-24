<?php

declare(strict_types=1);

namespace RunnerDeck;

final class FailedJobs
{
    /** @var list<string> */
    private const FAILED = ['failure', 'timed_out', 'startup_failure'];

    /**
     * Latest completed job per agent from the queue cache (no extra GitHub call).
     *
     * @param array<int, array<string, mixed>> $runners
     * @return array<int, array<string, mixed>>
     */
    public static function annotate(array $runners): array
    {
        $latest = self::latestByRunner(QueuedJobs::peekRecent());
        foreach ($runners as &$row) {
            $name = (string) ($row['agent_name'] ?? '');
            $job = $latest[$name] ?? null;
            $conclusion = is_array($job) ? (string) ($job['conclusion'] ?? '') : '';
            $row['last_job_failed'] = $job !== null && in_array($conclusion, self::FAILED, true);
            $row['last_job_conclusion'] = $conclusion;
            $row['last_job_name'] = is_array($job) ? (string) ($job['name'] ?? '') : '';
        }
        unset($row);
        return $runners;
    }

    /**
     * @param list<array<string, mixed>> $recent
     * @return array<string, array<string, mixed>>
     */
    public static function latestByRunner(array $recent): array
    {
        $out = [];
        foreach ($recent as $job) {
            $name = (string) ($job['runner_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $at = (int) ($job['completed_at'] ?? 0);
            $id = (int) ($job['id'] ?? 0);
            $prev = $out[$name] ?? null;
            $prevAt = is_array($prev) ? (int) ($prev['completed_at'] ?? 0) : 0;
            $prevId = is_array($prev) ? (int) ($prev['id'] ?? 0) : 0;
            if ($prev === null || $at > $prevAt || ($at === $prevAt && $id > $prevId)) {
                $out[$name] = $job;
            }
        }
        return $out;
    }
}
