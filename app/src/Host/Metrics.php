<?php

declare(strict_types=1);

namespace RunnerDeck\Host;

use RunnerDeck\Alert\WebhookOutbox;
use RunnerDeck\Core\Config;
use RunnerDeck\GitHub\QueuedJobs;
use RunnerDeck\Pool\RunnerPool;

/**
 * Prometheus text for GET /metrics.php. Local files and process list only —
 * no GitHub, no webhook, no runner start.
 */
final class Metrics
{
    public static function text(): string
    {
        $lines = [];
        $running = 0;
        $total = 0;
        foreach (RunnerPool::discover(0) as $info) {
            $total++;
            $row = $info->toArray();
            $id = self::esc((string) $row['id']);
            $isRunning = !empty($row['local_running']) ? 1 : 0;
            $running += $isRunning;
            $lines[] = 'runnerdeck_slot_running{id="' . $id . '"} ' . $isRunning;
            $cpu = $row['cpu_percent'] ?? null;
            if (is_float($cpu) || is_int($cpu)) {
                $lines[] = 'runnerdeck_slot_cpu_percent{id="' . $id . '"} ' . (float) $cpu;
            }
            $rss = $row['rss_kb'] ?? null;
            if (is_int($rss) || (is_string($rss) && ctype_digit($rss))) {
                $lines[] = 'runnerdeck_slot_rss_bytes{id="' . $id . '"} ' . ((int) $rss * 1024);
            }
        }

        $pressure = HostPressure::snapshot();
        $out = [
            '# HELP runnerdeck_info RunnerDeck version',
            '# TYPE runnerdeck_info gauge',
            'runnerdeck_info{version="' . self::esc(Config::version()) . '"} 1',
            '# HELP runnerdeck_runners Local runner slots',
            '# TYPE runnerdeck_runners gauge',
            'runnerdeck_runners{state="total"} ' . $total,
            'runnerdeck_runners{state="running"} ' . $running,
            '# HELP runnerdeck_maintenance Host maintenance flag',
            '# TYPE runnerdeck_maintenance gauge',
            'runnerdeck_maintenance ' . (HostMode::isMaintenance() ? 1 : 0),
            '# HELP runnerdeck_reboot_ready Pool drained for a host reboot',
            '# TYPE runnerdeck_reboot_ready gauge',
            'runnerdeck_reboot_ready ' . (HostMode::isRebootReady() ? 1 : 0),
            '# HELP runnerdeck_host_pressure 1 when new starts are refused',
            '# TYPE runnerdeck_host_pressure gauge',
            'runnerdeck_host_pressure ' . ($pressure['ok'] ? 0 : 1),
            '# HELP runnerdeck_queue_jobs Queued GitHub jobs from last cache (0 if never polled)',
            '# TYPE runnerdeck_queue_jobs gauge',
            'runnerdeck_queue_jobs ' . QueuedJobs::peekCachedCount(),
            '# HELP runnerdeck_webhook_outbox Failed crash-webhook posts waiting to retry',
            '# TYPE runnerdeck_webhook_outbox gauge',
            'runnerdeck_webhook_outbox ' . WebhookOutbox::pendingCount(),
        ];
        $disk = $pressure['disk_free_kb'];
        if (is_int($disk)) {
            $out[] = '# HELP runnerdeck_disk_free_bytes Pool filesystem free space';
            $out[] = '# TYPE runnerdeck_disk_free_bytes gauge';
            $out[] = 'runnerdeck_disk_free_bytes ' . ($disk * 1024);
        }
        $ram = $pressure['ram_available_kb'];
        if (is_int($ram)) {
            $out[] = '# HELP runnerdeck_ram_available_bytes Linux MemAvailable';
            $out[] = '# TYPE runnerdeck_ram_available_bytes gauge';
            $out[] = 'runnerdeck_ram_available_bytes ' . ($ram * 1024);
        }
        if ($lines !== []) {
            $out[] = '# HELP runnerdeck_slot_running 1 if that slot has a local process';
            $out[] = '# TYPE runnerdeck_slot_running gauge';
            $out[] = '# HELP runnerdeck_slot_cpu_percent Slot process CPU percent';
            $out[] = '# TYPE runnerdeck_slot_cpu_percent gauge';
            $out[] = '# HELP runnerdeck_slot_rss_bytes Slot process RSS';
            $out[] = '# TYPE runnerdeck_slot_rss_bytes gauge';
            $out = array_merge($out, $lines);
        }
        return implode("\n", $out) . "\n";
    }

    private static function esc(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ''], $value);
    }
}
