<?php

declare(strict_types=1);

namespace RunnerDeck\Pool;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RunnerDeck\Configuration\Config;
use RunnerDeck\Host\SlotDisk;

/**
 * Where runner files live, and how much disk a Start all needs.
 * The pool must not sit under a package.json: Node walks parents of the
 * runner's hashFiles script and treats it as an ES module when one says
 * "type": "module".
 */
final class PoolLayout
{
    /** One shared externals tree, the size that filled a small disk twice. */
    public const EXTERNALS_KIB = 600 * 1024;

    /** One shared bin tree (the listener and its libraries). */
    public const BIN_KIB = 150 * 1024;

    /** Per-slot files that stay private: config, credentials, logs. */
    public const SLOT_KIB = 20 * 1024;

    public static function packageJsonBlockMessage(string $poolDir): ?string
    {
        $found = self::parentPackageJson($poolDir);
        if ($found === null) {
            return null;
        }
        return 'pool directory ' . $poolDir . ' is under ' . $found
            . ' — Node loads the runner hashFiles script as an ES module.'
            . ' Move RUNNERDECK_POOL_DIR outside that tree.';
    }

    public static function parentPackageJson(string $poolDir): ?string
    {
        $current = $poolDir;
        $seen = [];
        while ($current !== '' && !isset($seen[$current])) {
            $seen[$current] = true;
            $candidate = $current . '/package.json';
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
        return null;
    }

    public static function slotCount(): int
    {
        $pool = Config::poolDir();
        $count = 0;
        foreach (glob($pool . '/runner-*') ?: [] as $path) {
            if (is_dir($path)) {
                $count++;
            }
        }
        return $count;
    }

    public static function estimatedKiB(int $slots): int
    {
        return self::EXTERNALS_KIB + self::BIN_KIB + (max(1, $slots) * self::SLOT_KIB);
    }

    public static function spaceSentence(int $slots, ?int $freeKiB): string
    {
        $slots = max(1, $slots);
        $noun = $slots === 1 ? '1 slot needs' : $slots . ' slots need';
        $sentence = $noun . ' about ' . SlotDisk::formatKb(self::estimatedKiB($slots))
            . ' once bin and externals are shared';
        if ($freeKiB === null) {
            return $sentence . '; free space is unknown';
        }
        return $sentence . '; ' . SlotDisk::formatKb($freeKiB) . ' free';
    }

    /** More than one slot still has its own externals tree. */
    public static function externalsDuplicated(): bool
    {
        return self::treeDuplicated('externals');
    }

    /** More than one slot still has its own bin or externals tree. */
    public static function agentTreesDuplicated(): bool
    {
        return self::treeDuplicated('bin') || self::treeDuplicated('externals');
    }

    /**
     * Point this slot's bin and externals files at another slot's copy of the
     * same agent. Hardlinks keep the files if the donor slot is removed.
     * Each listener is still its own process.
     */
    public static function shareExternals(string $dir): void
    {
        self::shareTree($dir, 'externals');
        self::shareTree($dir, 'bin');
    }

    public static function whitespaceMessage(string $poolDir): ?string
    {
        if (preg_match('/\s/', $poolDir) !== 1) {
            return null;
        }
        return 'pool directory ' . $poolDir
            . ' contains whitespace. GitHub Actions starts each step with an unquoted path,'
            . ' so the job fails before the script runs.';
    }

    private static function shareTree(string $dir, string $name): void
    {
        $own = $dir . '/' . $name;
        if (is_link($own) || !is_dir($own)) {
            return;
        }
        $donor = self::treeDonor($dir, $name);
        if ($donor === null) {
            return;
        }
        self::hardlinkTree($donor, $own);
    }

    private static function treeDuplicated(string $name): bool
    {
        $inodes = [];
        $pool = Config::poolDir();
        foreach (glob($pool . '/runner-*') ?: [] as $slot) {
            if (!is_dir($slot)) {
                continue;
            }
            $tree = $slot . '/' . $name;
            if (!is_dir($tree) || is_link($tree)) {
                continue;
            }
            $inode = self::firstFileInode($tree);
            if ($inode === null) {
                continue;
            }
            $inodes[$inode] = true;
        }
        return count($inodes) > 1;
    }

    private static function treeDonor(string $dir, string $name): ?string
    {
        $ownSize = self::agentBinarySize($dir);
        if ($ownSize === null) {
            return null;
        }
        $pool = dirname($dir);
        $ownReal = realpath($dir . '/' . $name);
        foreach (glob($pool . '/runner-*') ?: [] as $sibling) {
            if (!is_dir($sibling) || realpath($sibling) === realpath($dir)) {
                continue;
            }
            $tree = $sibling . '/' . $name;
            if (!is_dir($tree) || is_link($tree)) {
                continue;
            }
            if ($ownReal !== false && realpath($tree) === $ownReal) {
                continue;
            }
            if (self::agentBinarySize($sibling) !== $ownSize) {
                continue;
            }
            return $tree;
        }
        return null;
    }

    private static function agentBinarySize(string $dir): ?int
    {
        foreach (['bin/Runner.Listener', 'bin/Runner.Listener.dll', 'bin/Runner.Listener.exe'] as $relative) {
            $path = $dir . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }
            $size = filesize($path);
            return $size === false ? null : $size;
        }
        return null;
    }

    private static function hardlinkTree(string $donor, string $own): void
    {
        $donorReal = rtrim(str_replace('\\', '/', $donor), '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($donor, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $src = str_replace('\\', '/', $item->getPathname());
            if (!str_starts_with($src, $donorReal . '/')) {
                continue;
            }
            $target = $own . '/' . substr($src, strlen($donorReal) + 1);
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                    return;
                }
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }
            $srcStat = @stat($item->getPathname());
            $dstStat = is_file($target) ? @stat($target) : false;
            if (
                $srcStat !== false
                && $dstStat !== false
                && (int) $srcStat['ino'] === (int) $dstStat['ino']
                && (int) $srcStat['ino'] !== 0
            ) {
                continue;
            }
            $tmp = $target . '.rdlink';
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            if (!@link($item->getPathname(), $tmp)) {
                return;
            }
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                return;
            }
        }
    }

    private static function firstFileInode(string $externals): ?int
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($externals, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $stat = @stat($item->getPathname());
            if ($stat === false) {
                return null;
            }
            return (int) $stat['ino'];
        }
        return null;
    }
}
