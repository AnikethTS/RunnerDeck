<?php

declare(strict_types=1);

namespace RunnerDeck\Host;

use RunnerDeck\Alert\CrashWebhook;
use RunnerDeck\Alert\DiskAlert;
use RunnerDeck\Core\Config;
use RunnerDeck\Core\Settings;

/**
 * Refuse new starts when this machine is out of disk or RAM.
 * Running jobs are not stopped. Unknown readings fail open.
 */
final class HostPressure
{
    private const KIB = 1024;

    /** @var (callable(): array{0: ?int, 1: ?int})|null bytes free, bytes total */
    private static $diskFake = null;

    /** @var (callable(): ?int)|null available RAM in KiB */
    private static $ramFake = null;

    /** @param (callable(): array{0: ?int, 1: ?int})|null $handler */
    public static function fakeDisk(?callable $handler): void
    {
        self::$diskFake = $handler;
    }

    /** @param (callable(): ?int)|null $handler */
    public static function fakeRam(?callable $handler): void
    {
        self::$ramFake = $handler;
    }

    /**
     * @return array{
     *     ok: bool,
     *     reasons: list<string>,
     *     disk_free_kb: ?int,
     *     disk_total_kb: ?int,
     *     ram_available_kb: ?int
     * }
     */
    public static function snapshot(): array
    {
        [$diskFreeKb, $diskTotalKb] = self::diskKb();
        $ramKb = self::ramAvailableKb();
        $reasons = [];

        $needDiskKb = Config::minFreeDiskGib() * DiskAlert::KIB_PER_GIB;
        if ($needDiskKb > 0 && $diskFreeKb !== null && $diskFreeKb < $needDiskKb) {
            $reasons[] = 'disk ' . SlotDisk::formatKb($diskFreeKb)
                . ' free (need ' . Config::minFreeDiskGib() . ' GiB)';
        }

        $needRamKb = Config::minFreeRamMib() * self::KIB;
        if ($needRamKb > 0 && $ramKb !== null && $ramKb < $needRamKb) {
            $reasons[] = 'RAM ' . SlotDisk::formatKb($ramKb)
                . ' available (need ' . Config::minFreeRamMib() . ' MiB)';
        }

        return [
            'ok' => $reasons === [],
            'reasons' => $reasons,
            'disk_free_kb' => $diskFreeKb,
            'disk_total_kb' => $diskTotalKb,
            'ram_available_kb' => $ramKb,
        ];
    }

    public static function blockStartMessage(): ?string
    {
        $snap = self::snapshot();
        if ($snap['ok']) {
            return null;
        }
        return 'This host is low on ' . implode(' and ', $snap['reasons'])
            . ' — free resources before starting runners';
    }

    /**
     * One-shot webhook when pressure starts refusing starts. Not called from health.php.
     *
     * @param array{
     *     ok: bool,
     *     reasons: list<string>,
     *     disk_free_kb: ?int,
     *     disk_total_kb: ?int,
     *     ram_available_kb: ?int
     * }|null $snap
     */
    public static function checkAlert(?array $snap = null): void
    {
        if (!CrashWebhook::wantsNotify()) {
            return;
        }
        $snap = $snap ?? self::snapshot();
        $over = !$snap['ok'];
        $path = Settings::storageDir() . '/pressure_alert.json';
        $was = false;
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $was = is_array($decoded) && !empty($decoded['fired']);
        }
        if ($over && !$was) {
            $detail = $snap['reasons'] !== [] ? implode('; ', $snap['reasons']) : 'disk or RAM';
            CrashWebhook::notifyEvent(
                'host_pressure',
                'RunnerDeck: this host is low on ' . $detail . ' — new runners will not start.'
            );
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode(['fired' => $over], JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    /** @return array{0: ?int, 1: ?int} */
    private static function diskKb(): array
    {
        if (self::$diskFake !== null) {
            [$free, $total] = (self::$diskFake)();
            return [
                $free !== null ? intdiv($free, 1024) : null,
                $total !== null ? intdiv($total, 1024) : null,
            ];
        }
        $path = self::diskPath();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        return [
            is_float($free) ? intdiv((int) $free, 1024) : null,
            is_float($total) ? intdiv((int) $total, 1024) : null,
        ];
    }

    private static function ramAvailableKb(): ?int
    {
        if (self::$ramFake !== null) {
            return (self::$ramFake)();
        }
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo !== false && preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $meminfo, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private static function diskPath(): string
    {
        $dir = Config::poolDir();
        while ($dir !== '' && !is_dir($dir)) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return is_dir($dir) ? $dir : sys_get_temp_dir();
    }
}
