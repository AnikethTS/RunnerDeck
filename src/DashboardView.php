<?php

declare(strict_types=1);

namespace RunnerDeck;

final class DashboardView
{
    /**
     * @param array<string, mixed> $snapshot
     * @return array{hidden: bool, text: string}
     */
    public static function healthBanner(array $snapshot): array
    {
        $h = is_array($snapshot['health'] ?? null) ? $snapshot['health'] : [];
        $loggedIn = (bool) ($h['logged_in'] ?? false);
        $orgOk = (bool) ($h['org_access_ok'] ?? false);
        $message = (string) ($h['message'] ?? '');
        if ($loggedIn && $orgOk) {
            return ['hidden' => true, 'text' => ''];
        }
        $text = !$loggedIn
            ? 'gh CLI is not authenticated: ' . $message
            : 'gh CLI is logged in but cannot read runners: ' . $message;
        return ['hidden' => false, 'text' => $text];
    }

    /** @param array<string, mixed> $snapshot */
    public static function lastUpdated(array $snapshot): string
    {
        $ts = (int) ($snapshot['generated_at'] ?? 0);
        if ($ts <= 0) {
            return '';
        }
        return 'updated ' . date('g:i:s A', $ts);
    }

    public static function loadClass(?float $percent): string
    {
        if ($percent === null) {
            return '';
        }
        if ($percent > 85) {
            return 'stat-critical';
        }
        if ($percent > 60) {
            return 'stat-warning';
        }
        return '';
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, string>
     */
    public static function statsCards(array $snapshot): array
    {
        $s = is_array($snapshot['stats'] ?? null) ? $snapshot['stats'] : [];
        $h = is_array($snapshot['health'] ?? null) ? $snapshot['health'] : [];
        $sys = is_array($snapshot['system'] ?? null) ? $snapshot['system'] : [];
        $running = (int) ($s['running'] ?? 0);
        $total = (int) ($s['total'] ?? 0);
        $connected = (bool) ($h['logged_in'] ?? false) && (bool) ($h['org_access_ok'] ?? false);
        $avgCpu = $s['avg_cpu_percent'] ?? null;
        $rss = $s['total_rss_kb'] ?? null;
        $mb = is_numeric($rss) ? (string) (int) round(((float) $rss) / 1024) : null;

        $cpuPct = isset($sys['cpu_percent']) && is_numeric($sys['cpu_percent'])
            ? (float) $sys['cpu_percent'] : null;
        $memPct = isset($sys['mem_percent']) && is_numeric($sys['mem_percent'])
            ? (float) $sys['mem_percent'] : null;
        $cores = (int) ($sys['cpu_cores'] ?? 0);
        $usedKb = (float) ($sys['mem_used_kb'] ?? 0);
        $totalKb = $sys['mem_total_kb'] ?? null;
        $usedMb = (string) (int) round($usedKb / 1024);
        $totalMb = is_numeric($totalKb) ? (string) (int) round(((float) $totalKb) / 1024) : null;

        return [
            'active' => (string) $running,
            'active_sub' => 'of ' . $total . ' configured',
            'github' => $connected ? 'Connected' : 'Issue',
            'github_class' => 'stat-value ' . ($connected ? 'stat-good' : 'stat-critical'),
            'github_sub' => $connected ? 'org/repo reachable' : (string) ($h['message'] ?? ''),
            'cpu' => is_numeric($avgCpu) ? number_format((float) $avgCpu, 1) . '%' : '—',
            'cpu_sub' => $running ? 'across ' . $running . ' running' : 'no runners active',
            'mem' => $mb !== null ? $mb . ' MB' : '—',
            'mem_sub' => $running ? 'across ' . $running . ' running' : 'no runners active',
            'sys_cpu' => $cpuPct !== null ? number_format($cpuPct, 1) . '%' : '—',
            'sys_cpu_class' => 'stat-value ' . self::loadClass($cpuPct),
            'sys_cpu_sub' => $cores . ' core' . ($cores === 1 ? '' : 's') . ', whole machine',
            'sys_mem' => $memPct !== null ? (string) (int) round($memPct) . '%' : '—',
            'sys_mem_class' => 'stat-value ' . self::loadClass($memPct),
            'sys_mem_sub' => $totalMb !== null
                ? $usedMb . ' of ' . $totalMb . ' MB'
                : $usedMb . ' MB used, total unknown',
        ];
    }

    /** @param array<int, array<string, mixed>> $runners */
    public static function rowsHtml(array $runners): string
    {
        $html = '';
        foreach ($runners as $runner) {
            $html .= self::rowHtml($runner);
        }
        return $html;
    }

    /** @param array<string, mixed> $runner */
    public static function rowHtml(array $runner): string
    {
        $id = self::e((string) ($runner['id'] ?? ''));
        $name = self::e((string) ($runner['agent_name'] ?? ''));
        $log = $runner['log_tail'] ?? [];
        $logPreview = is_array($log) ? implode("\n", array_map('strval', $log)) : '';
        if ($logPreview === '') {
            $logPreview = '(no log yet)';
        }
        $running = (bool) ($runner['local_running'] ?? false);
        $startDis = $running ? ' disabled' : '';
        $stopDis = $running ? '' : ' disabled';
        $drainDis = $running ? '' : ' disabled';
        $clearDis = !empty($runner['can_clear_work']) ? '' : ' disabled';

        return ''
            . '    <tr data-runner="' . $id . '" data-agent-name="' . $name . '">' . "\n"
            . '      <td class="select-col">' . "\n"
            . '        <input type="checkbox" class="row-select" data-runner="' . $id . '" />' . "\n"
            . '      </td>' . "\n"
            . '      <td>' . "\n"
            . '        <span class="runner-name">' . $id . '</span>' . "\n"
            . '        <span class="agent-name">' . $name . '</span>' . "\n"
            . '      </td>' . "\n"
            . '      <td>' . self::githubCell($runner) . '</td>' . "\n"
            . '      <td>' . self::localCell($runner) . '</td>' . "\n"
            . '      <td>' . "\n"
            . '        <pre class="log-preview">' . self::e($logPreview) . '</pre>' . "\n"
            . '        <button class="log-link" data-action="view-log">View live log &rarr;</button>' . "\n"
            . '      </td>' . "\n"
            . '      <td>' . "\n"
            . '        <div class="row-actions">' . "\n"
            . '          <button class="btn btn-sm btn-good" data-action="start"'
            . $startDis . '>Start</button>' . "\n"
            . '          <button class="btn btn-sm btn-critical" data-action="stop"'
            . $stopDis . '>Stop</button>' . "\n"
            . '          <button class="btn btn-sm" data-action="drain"'
            . $drainDis . '>Drain</button>' . "\n"
            . '          <button class="btn btn-sm" data-action="restart">Restart</button>' . "\n"
            . '          <button class="btn btn-sm" data-action="rename">Rename</button>' . "\n"
            . '          <button class="btn btn-sm" data-action="clear-work"'
            . $clearDis . '>Clear work</button>' . "\n"
            . '          <button class="btn btn-sm btn-critical" data-action="delete">Delete</button>' . "\n"
            . '        </div>' . "\n"
            . '      </td>' . "\n"
            . '    </tr>' . "\n";
    }

    /** @param array<string, mixed> $runner */
    private static function githubCell(array $runner): string
    {
        $gh = $runner['github'] ?? null;
        if (!is_array($gh)) {
            return self::badge('unknown', 'muted')
                . self::flag($runner, 'mismatch_flagged', 'local/GitHub status disagree', 'warning');
        }
        $status = (string) ($gh['status'] ?? '');
        $statusCls = $status === 'online' ? 'good' : 'critical';
        $busy = (bool) ($gh['busy'] ?? false);
        $html = self::badge($status, $statusCls)
            . ' ' . self::badge($busy ? 'busy' : 'idle', $busy ? 'warning' : 'good');
        $labels = $gh['labels'] ?? [];
        if (is_array($labels) && $labels !== []) {
            $chips = '';
            foreach ($labels as $label) {
                $chips .= '<span class="label-chip">' . self::e((string) $label) . '</span>';
            }
            $html .= '<div class="label-chips">' . $chips . '</div>';
        }
        return $html . self::flag($runner, 'mismatch_flagged', 'local/GitHub status disagree', 'warning');
    }

    /** @param array<string, mixed> $runner */
    private static function localCell(array $runner): string
    {
        if (empty($runner['configured'])) {
            $badge = self::badge('not configured', 'muted');
        } elseif (!empty($runner['local_running'])) {
            $badge = self::badge('running (pid ' . (string) ($runner['pid'] ?? '') . ')', 'good');
        } else {
            $badge = self::badge('stopped', 'critical');
        }
        return $badge . self::resourceUsage($runner) . self::diskUsage($runner)
            . self::flag($runner, 'crash_flagged', 'crashed unexpectedly', 'critical')
            . self::crashHistoryBadge($runner);
    }

    /** @param array<string, mixed> $runner */
    private static function resourceUsage(array $runner): string
    {
        if (
            empty($runner['local_running'])
            || !is_numeric($runner['cpu_percent'] ?? null)
            || !is_numeric($runner['rss_kb'] ?? null)
        ) {
            return '';
        }
        $cpu = (float) $runner['cpu_percent'];
        $mb = (string) (int) round(((float) $runner['rss_kb']) / 1024);
        $uptime = self::formatUptime(
            is_numeric($runner['uptime_seconds'] ?? null) ? (int) $runner['uptime_seconds'] : null
        );
        $uptimeText = $uptime !== null ? ' &middot; up ' . self::e($uptime) : '';
        return '<span class="resource-usage">' . self::miniBar($cpu)
            . htmlspecialchars(number_format($cpu, 1), ENT_QUOTES, 'UTF-8')
            . '% CPU &middot; ' . $mb . ' MB' . $uptimeText . '</span>';
    }

    /** @param array<string, mixed> $runner */
    private static function diskUsage(array $runner): string
    {
        if (!array_key_exists('disk_kb', $runner) || $runner['disk_kb'] === null || !is_numeric($runner['disk_kb'])) {
            return '';
        }
        $kb = (int) $runner['disk_kb'];
        $text = $kb >= 1024
            ? number_format($kb / 1024, 1) . ' MB disk'
            : $kb . ' KB disk';
        return '<span class="resource-usage disk-usage">' . self::e($text) . '</span>';
    }

    private static function miniBar(float $percent): string
    {
        $pct = max(0.0, min(100.0, $percent));
        $cls = $percent > 80 ? 'mini-bar-critical' : ($percent > 50 ? 'mini-bar-warn' : '');
        $width = htmlspecialchars((string) $pct, ENT_QUOTES, 'UTF-8');
        return '<span class="mini-bar"><span class="mini-bar-fill ' . $cls . '" style="width:'
            . $width . '%"></span></span>';
    }

    private static function formatUptime(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $mins = intdiv($seconds, 60);
        if ($mins < 60) {
            return $mins . 'm';
        }
        $hours = intdiv($mins, 60);
        $remMins = $mins % 60;
        if ($hours < 24) {
            return $hours . 'h ' . $remMins . 'm';
        }
        $days = intdiv($hours, 24);
        return $days . 'd ' . ($hours % 24) . 'h';
    }

    /** @param array<string, mixed> $runner */
    private static function flag(array $runner, string $key, string $text, string $cls): string
    {
        if (empty($runner[$key])) {
            return '';
        }
        return '<div class="row-flag">' . self::badge($text, $cls) . '</div>';
    }

    /** @param array<string, mixed> $runner */
    private static function crashHistoryBadge(array $runner): string
    {
        $n = $runner['crash_count_7d'] ?? 0;
        if (!is_numeric($n) || (int) $n < 1) {
            return '';
        }
        $count = (int) $n;
        $text = $count === 1 ? '1 crash (7d)' : $count . ' crashes (7d)';
        return '<div class="row-flag"><button type="button" class="crash-history-btn" data-action="crash-history" '
            . 'aria-label="Show crash times for the last 7 days">'
            . self::badge($text, 'warning') . '</button></div>';
    }

    private static function badge(string $text, string $cls): string
    {
        return '<span class="badge badge-' . self::e($cls) . '">' . self::e($text) . '</span>';
    }

    /** @param array<int|string, mixed> $entries */
    public static function errorsPanel(array $entries): string
    {
        $normalized = self::normalizeErrors($entries);
        $count = count($normalized);
        $countText = $count > 0 ? ' (' . $count . ')' : '';
        $open = $count > 0 ? ' open' : '';
        return '      <details id="app-log" class="app-log"' . $open . ">\n"
            . '        <summary>Recent errors<span id="app-log-count" class="muted">'
            . self::e($countText) . "</span></summary>\n"
            . '        <div id="app-log-body">' . self::errorsBody($normalized) . "</div>\n"
            . "      </details>\n";
    }

    /** @param array<int|string, mixed> $entries */
    public static function errorsBody(array $entries): string
    {
        $normalized = self::normalizeErrors($entries);
        if ($normalized === []) {
            return '<p class="muted">No errors logged yet.</p>';
        }
        $html = '<ol class="app-log-list">';
        foreach (array_reverse($normalized) as $entry) {
            $meta = self::formatErrorTime($entry['time']) . ' &middot; ' . self::e($entry['action']);
            if (isset($entry['runner'])) {
                $meta .= ' &middot; ' . self::e($entry['runner']);
            }
            $html .= '<li class="app-log-item"><div class="app-log-meta">' . $meta
                . '</div><div>' . self::e($entry['message']) . '</div>';
            if (isset($entry['stderr'])) {
                $html .= '<pre class="app-log-stderr">' . self::e($entry['stderr']) . '</pre>';
            }
            $html .= '</li>';
        }
        return $html . '</ol>';
    }

    /**
     * @param array<int|string, mixed> $entries
     * @return list<array{time: string, action: string, message: string, runner?: string, stderr?: string}>
     */
    private static function normalizeErrors(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $action = $entry['action'] ?? null;
            $message = $entry['message'] ?? null;
            $time = $entry['time'] ?? null;
            if (!is_string($action) || !is_string($message) || !is_string($time)) {
                continue;
            }
            $row = [
                'time' => $time,
                'action' => $action,
                'message' => $message,
            ];
            $runner = $entry['runner'] ?? null;
            if (is_string($runner) && $runner !== '') {
                $row['runner'] = $runner;
            }
            $stderr = $entry['stderr'] ?? null;
            if (is_string($stderr) && $stderr !== '') {
                $row['stderr'] = $stderr;
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function formatErrorTime(string $iso): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
