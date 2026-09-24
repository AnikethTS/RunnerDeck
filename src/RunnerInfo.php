<?php

declare(strict_types=1);

namespace RunnerDeck;

final class RunnerInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $dir,
        public readonly bool $configured,
        public readonly string $agentName,
        public readonly bool $localRunning,
        public readonly ?int $pid,
        public readonly array $logTail,
        public readonly ?float $cpuPercent = null,
        public readonly ?int $rssKb = null,
        public readonly ?int $uptimeSeconds = null,
        public readonly ?int $diskKb = null,
        public readonly bool $canClearWork = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'configured' => $this->configured,
            'agent_name' => $this->agentName,
            'local_running' => $this->localRunning,
            'pid' => $this->pid,
            'log_tail' => $this->logTail,
            'cpu_percent' => $this->cpuPercent,
            'rss_kb' => $this->rssKb,
            'uptime_seconds' => $this->uptimeSeconds,
            'disk_kb' => $this->diskKb,
            'can_clear_work' => $this->canClearWork,
        ];
    }
}
