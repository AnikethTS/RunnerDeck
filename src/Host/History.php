<?php

declare(strict_types=1);

namespace RunnerDeck\Host;

// Rolling pool-wide CPU/RAM history, sampled once per Dashboard::snapshot()
// call (the existing poll cycle is the sampler — no separate daemon/cron).
// Best-effort throughout: a missing pdo_sqlite extension or a write failure
// degrades to "no history," never breaks the rest of the dashboard.
final class History
{
    public const HOUR_SECONDS = 3600;
    public const DAY_SECONDS = 86400;
    private const RETENTION_SECONDS = self::DAY_SECONDS;

    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    public static function path(): string
    {
        return getenv('RUNNERDECK_HISTORY_FILE') ?: dirname(__DIR__, 2) . '/storage/db/history.sqlite';
    }

    private static function db(): \PDO
    {
        static $pdo = null;
        if ($pdo !== null) {
            return $pdo;
        }

        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("failed to create {$dir}");
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS samples (
            ts INTEGER NOT NULL,
            avg_cpu REAL NOT NULL,
            total_rss_kb INTEGER NOT NULL,
            disk_free_kb INTEGER
        )');
        try {
            $pdo->exec('ALTER TABLE samples ADD COLUMN disk_free_kb INTEGER');
        } catch (\Throwable) {
            // Column already exists on current databases.
        }
        return $pdo;
    }

    public static function record(float $avgCpu, int $totalRssKb, ?int $diskFreeKb = null): void
    {
        try {
            $db = self::db();
            $db->prepare(
                'INSERT INTO samples (ts, avg_cpu, total_rss_kb, disk_free_kb) VALUES (?, ?, ?, ?)'
            )->execute([time(), $avgCpu, $totalRssKb, $diskFreeKb]);
            $db->prepare('DELETE FROM samples WHERE ts < ?')
                ->execute([time() - self::RETENTION_SECONDS]);
        } catch (\Throwable) {
            // History is a nice-to-have; never let it take the dashboard down.
        }
    }

    /**
     * @return array<int, array{ts: int, avg_cpu: float, total_rss_kb: int, disk_free_kb: ?int}>
     */
    public static function recent(int $seconds = self::HOUR_SECONDS): array
    {
        $seconds = max(60, min(self::DAY_SECONDS, $seconds));
        try {
            $db = self::db();
            $stmt = $db->prepare(
                'SELECT ts, avg_cpu, total_rss_kb, disk_free_kb FROM samples WHERE ts >= ? ORDER BY ts ASC'
            );
            $stmt->execute([time() - $seconds]);
            return array_map(static fn($row) => [
                'ts' => (int) $row['ts'],
                'avg_cpu' => (float) $row['avg_cpu'],
                'total_rss_kb' => (int) $row['total_rss_kb'],
                'disk_free_kb' => is_numeric($row['disk_free_kb'] ?? null)
                    ? (int) $row['disk_free_kb']
                    : null,
            ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Cap SVG point count so a 24h poll stream stays readable.
     *
     * @param array<int, array<string, mixed>> $samples
     * @return array<int, array<string, mixed>>
     */
    public static function downsample(array $samples, int $maxPoints = 400): array
    {
        $n = count($samples);
        if ($n <= $maxPoints || $maxPoints < 2) {
            return $samples;
        }
        $out = [];
        $last = $n - 1;
        for ($i = 0; $i < $maxPoints; $i++) {
            $idx = (int) round($i * $last / ($maxPoints - 1));
            $out[] = $samples[$idx];
        }
        return $out;
    }

    /**
     * @param list<float> $values
     * @return array{now: string, span: string}
     */
    public static function summary(
        array $values,
        string $suffix,
        int $decimals,
        string $window = 'this hour'
    ): array {
        if ($values === []) {
            return ['now' => '—', 'span' => ''];
        }
        $last = $values[array_key_last($values)];
        $lo = min($values);
        $hi = max($values);
        return [
            'now' => self::formatTick($last, $suffix, $decimals),
            'span' => self::formatTick($lo, $suffix, $decimals)
                . '–' . self::formatTick($hi, $suffix, $decimals) . ' ' . $window,
        ];
    }

    /** @param list<float> $values */
    public static function polylineSvg(
        array $values,
        float $min,
        float $max,
        bool $unavailable,
        string $suffix = '',
        int $decimals = 0,
        string $horizon = '-1h'
    ): string {
        if ($unavailable) {
            return '<text x="12" y="58" font-size="11" fill="currentColor" opacity="0.55">'
                . 'unavailable — php-pdo_sqlite not installed</text>';
        }
        if (count($values) < 2) {
            return '<text x="12" y="58" font-size="11" fill="currentColor" opacity="0.55">'
                . 'collecting data…</text>';
        }

        $vbW = 400.0;
        $vbH = 110.0;
        $padL = 42.0;
        $padR = 10.0;
        $padT = 10.0;
        $padB = 18.0;
        $plotW = $vbW - $padL - $padR;
        $plotH = $vbH - $padT - $padB;
        $range = ($max - $min) ?: 1.0;
        $step = $plotW / (count($values) - 1);

        $xy = [];
        foreach ($values as $i => $v) {
            $xy[] = [
                $padL + ($i * $step),
                $padT + $plotH - (($v - $min) / $range) * $plotH,
            ];
        }

        $points = [];
        foreach ($xy as [$x, $y]) {
            $points[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
        }
        $pointStr = implode(' ', $points);

        $first = $xy[0];
        $last = $xy[array_key_last($xy)];
        $bottom = $padT + $plotH;
        $line = [];
        foreach ($xy as [$px, $py]) {
            $line[] = number_format($px, 1, '.', '') . ' ' . number_format($py, 1, '.', '');
        }
        $area = 'M' . number_format($first[0], 1, '.', '') . ' ' . number_format($bottom, 1, '.', '')
            . ' L' . implode(' L', $line)
            . ' L' . number_format($last[0], 1, '.', '') . ' ' . number_format($bottom, 1, '.', '')
            . ' Z';

        $html = '';
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $frac) {
            $y = number_format($padT + ($frac * $plotH), 1, '.', '');
            $tick = $max - ($frac * $range);
            $html .= '<line class="history-rule" x1="' . number_format($padL, 1, '.', '')
                . '" y1="' . $y . '" x2="' . number_format($padL + $plotW, 1, '.', '')
                . '" y2="' . $y . '" />';
            $html .= '<text class="history-tick" x="4" y="'
                . number_format((float) $y + 3.0, 1, '.', '') . '">'
                . htmlspecialchars(self::formatTick($tick, $suffix, $decimals), ENT_QUOTES, 'UTF-8')
                . '</text>';
        }

        $html .= '<path class="history-area" d="' . $area . '" />';
        $html .= '<polyline class="history-line" points="' . $pointStr . '" />';
        $html .= '<circle class="history-dot" cx="' . number_format($last[0], 1, '.', '')
            . '" cy="' . number_format($last[1], 1, '.', '') . '" r="3" />';
        $html .= '<text class="history-tick" x="' . number_format($padL, 1, '.', '')
            . '" y="' . number_format($vbH - 4, 1, '.', '') . '">'
            . htmlspecialchars($horizon, ENT_QUOTES, 'UTF-8') . '</text>';
        $html .= '<text class="history-tick history-tick-end" x="'
            . number_format($padL + $plotW, 1, '.', '') . '" y="'
            . number_format($vbH - 4, 1, '.', '') . '">now</text>';

        return $html;
    }

    /**
     * @param list<array{label: string, count: int}> $bars
     */
    public static function barSvg(array $bars): string
    {
        if ($bars === []) {
            return '<text x="12" y="58" font-size="11" fill="currentColor" opacity="0.55">'
                . 'no crash events</text>';
        }
        $counts = array_map(static fn (array $b): int => max(0, $b['count']), $bars);
        $max = max(1, ...$counts);
        $vbW = 400.0;
        $vbH = 110.0;
        $padL = 42.0;
        $padR = 10.0;
        $padT = 10.0;
        $padB = 22.0;
        $plotW = $vbW - $padL - $padR;
        $plotH = $vbH - $padT - $padB;
        $n = count($bars);
        $gap = 6.0;
        $barW = ($plotW - ($gap * max(0, $n - 1))) / max(1, $n);
        $html = '';
        foreach ([0.0, 0.5, 1.0] as $frac) {
            $y = number_format($padT + ($frac * $plotH), 1, '.', '');
            $tick = (int) round($max * (1.0 - $frac));
            $html .= '<line class="history-rule" x1="' . number_format($padL, 1, '.', '')
                . '" y1="' . $y . '" x2="' . number_format($padL + $plotW, 1, '.', '')
                . '" y2="' . $y . '" />';
            $html .= '<text class="history-tick" x="4" y="'
                . number_format((float) $y + 3.0, 1, '.', '') . '">'
                . htmlspecialchars((string) $tick, ENT_QUOTES, 'UTF-8') . '</text>';
        }
        foreach ($bars as $i => $bar) {
            $h = ($bar['count'] / $max) * $plotH;
            $x = $padL + ($i * ($barW + $gap));
            $y = $padT + $plotH - $h;
            $html .= '<rect class="history-bar" x="' . number_format($x, 1, '.', '')
                . '" y="' . number_format($y, 1, '.', '')
                . '" width="' . number_format($barW, 1, '.', '')
                . '" height="' . number_format(max(0.5, $h), 1, '.', '') . '" />';
            $label = strlen($bar['label']) > 6 ? substr($bar['label'], 5) : $bar['label'];
            $html .= '<text class="history-tick" x="'
                . number_format($x + ($barW / 2), 1, '.', '')
                . '" y="' . number_format($vbH - 4, 1, '.', '')
                . '" text-anchor="middle">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</text>';
        }
        return $html;
    }

    private static function formatTick(float $value, string $suffix, int $decimals): string
    {
        $decimals = max(0, min(2, $decimals));
        return number_format($value, $decimals, '.', '') . $suffix;
    }
}
