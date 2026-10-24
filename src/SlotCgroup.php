<?php

declare(strict_types=1);

namespace RunnerDeck;

/**
 * Optional cgroup v2 CPU/memory caps applied after a runner starts.
 * Linux only. Missing controllers, permissions, or macOS fail open.
 */
final class SlotCgroup
{
    private const PERIOD = 100000;

    private static ?string $rootFake = null;

    public static function fakeRoot(?string $dir): void
    {
        self::$rootFake = $dir;
    }

    public static function apply(string $runnerId, int $pid): bool
    {
        $cpu = Config::slotCpuPercent();
        $mem = Config::slotMemoryMaxMib();
        if ($cpu === null && $mem === null) {
            return true;
        }
        if ($pid < 1 || !self::validId($runnerId)) {
            return false;
        }
        $root = self::root();
        if ($root === null) {
            return false;
        }
        $slot = $root . '/runnerdeck/' . $runnerId;
        if (!self::ensureDir($root . '/runnerdeck') || !self::ensureDir($slot)) {
            AppLog::errorThrottled('slot_cgroup', 'could not create cgroup for ' . $runnerId, [
                'runner' => $runnerId,
            ]);
            return false;
        }
        self::tryWrite($root . '/runnerdeck/cgroup.subtree_control', '+cpu +memory');
        if ($cpu !== null) {
            $quota = (int) ($cpu * (self::PERIOD / 100));
            if (!self::tryWrite($slot . '/cpu.max', $quota . ' ' . self::PERIOD)) {
                AppLog::errorThrottled('slot_cgroup', 'could not set cpu.max for ' . $runnerId, [
                    'runner' => $runnerId,
                ]);
            }
        }
        if ($mem !== null) {
            $bytes = (string) ($mem * 1024 * 1024);
            if (!self::tryWrite($slot . '/memory.max', $bytes)) {
                AppLog::errorThrottled('slot_cgroup', 'could not set memory.max for ' . $runnerId, [
                    'runner' => $runnerId,
                ]);
            }
        }
        if (!self::tryWrite($slot . '/cgroup.procs', (string) $pid)) {
            AppLog::errorThrottled('slot_cgroup', 'could not move pid into cgroup for ' . $runnerId, [
                'runner' => $runnerId,
            ]);
            return false;
        }
        return true;
    }

    private static function validId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,40}$/', $id) === 1;
    }

    private static function root(): ?string
    {
        $root = self::$rootFake ?? '/sys/fs/cgroup';
        if (!is_dir($root) || !is_file($root . '/cgroup.controllers')) {
            return null;
        }
        return $root;
    }

    private static function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, 0755, true) || is_dir($dir);
    }

    private static function tryWrite(string $path, string $value): bool
    {
        return @file_put_contents($path, $value) !== false;
    }
}
