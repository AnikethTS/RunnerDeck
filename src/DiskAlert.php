<?php

declare(strict_types=1);

namespace RunnerDeck;

final class DiskAlert
{
    public const KIB_PER_GIB = 1024 * 1024;

    /** @param array<int, array<string, mixed>> $runners */
    public static function check(array $runners, ?int $totalDiskKb): void
    {
        $thresholdGb = Config::diskWebhookThresholdGb();
        if ($thresholdGb === null || Config::crashWebhookUrl() === null) {
            return;
        }

        $limitKb = $thresholdGb * self::KIB_PER_GIB;
        $state = self::read();
        $slots = is_array($state['slots'] ?? null) ? $state['slots'] : [];
        $known = [];

        foreach ($runners as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $known[$id] = true;
            $kb = isset($r['disk_kb']) && is_numeric($r['disk_kb']) ? (int) $r['disk_kb'] : null;
            $over = $kb !== null && $kb >= $limitKb;
            $was = !empty($slots[$id]);
            if ($over && !$was) {
                CrashWebhook::notifyDisk(
                    'disk_slot',
                    'RunnerDeck: ' . (string) ($r['agent_name'] ?? $id) . " ({$id}) disk is "
                        . SlotDisk::formatKb((int) $kb) . " (threshold {$thresholdGb} GiB).",
                    $id,
                    (string) ($r['agent_name'] ?? $id),
                    (int) $kb,
                    $thresholdGb
                );
            }
            $slots[$id] = $over;
        }

        foreach (array_keys($slots) as $id) {
            if (!isset($known[$id])) {
                unset($slots[$id]);
            }
        }

        $poolOver = $totalDiskKb !== null && $totalDiskKb >= $limitKb;
        $poolWas = !empty($state['pool']);
        if ($poolOver && !$poolWas) {
            CrashWebhook::notifyDisk(
                'disk_pool',
                'RunnerDeck: pool disk is ' . SlotDisk::formatKb((int) $totalDiskKb)
                    . " (threshold {$thresholdGb} GiB).",
                null,
                null,
                (int) $totalDiskKb,
                $thresholdGb
            );
        }

        self::write(['pool' => $poolOver, 'slots' => $slots]);
    }

    /** @return array{pool?: bool, slots?: array<string, bool>} */
    private static function read(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array{pool: bool, slots: array<string, bool>} $state */
    private static function write(array $state): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $tmp = self::path() . '.tmp';
        file_put_contents($tmp, $json);
        @chmod($tmp, 0600);
        @rename($tmp, self::path());
    }

    private static function path(): string
    {
        return Settings::storageDir() . '/disk_alert.json';
    }
}
