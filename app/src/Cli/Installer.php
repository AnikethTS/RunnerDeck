<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

final class Installer
{
    public static function defaultDir(): string
    {
        $home = getenv('HOME') ?: '';
        return ($home !== '' ? $home : sys_get_temp_dir()) . '/.local/bin';
    }

    /** @return array{ok: bool, message: string} */
    public static function install(
        string $root,
        string $dir,
        bool $force = false,
        string $os = PHP_OS_FAMILY
    ): array {
        $script = $root . '/runnerdeck';
        $blocked = self::unsupported($root, $os);
        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked];
        }
        $link = rtrim($dir, '/') . '/runnerdeck';
        if (is_link($link) && readlink($link) === $script) {
            return ['ok' => true, 'message' => "Already installed: {$link}" . self::pathHint($dir)];
        }
        if (file_exists($link) || is_link($link)) {
            if (!$force) {
                return ['ok' => false, 'message' => "{$link} already exists. Use --force to replace it."];
            }
            @unlink($link);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'message' => "cannot create {$dir}"];
        }
        if (!@symlink($script, $link)) {
            return ['ok' => false, 'message' => "cannot link {$link}"];
        }
        return ['ok' => true, 'message' => "Installed: {$link} -> {$script}" . self::pathHint($dir)];
    }

    /** @return array{ok: bool, message: string} */
    public static function uninstall(string $root, string $dir): array
    {
        $script = $root . '/runnerdeck';
        $link = rtrim($dir, '/') . '/runnerdeck';
        if (!is_link($link)) {
            return ['ok' => false, 'message' => "nothing to remove: {$link} is not a link"];
        }
        if (readlink($link) !== $script) {
            return ['ok' => false, 'message' => "{$link} points somewhere else; not removing it"];
        }
        @unlink($link);
        return ['ok' => true, 'message' => "Removed {$link}"];
    }

    private static function unsupported(string $root, string $os): ?string
    {
        if ($os === 'Windows') {
            return 'the Windows installer already puts runnerdeck on your PATH';
        }
        if (!is_file($root . '/runnerdeck')) {
            return 'runnerdeck install is for a source checkout; a package already installs the command';
        }
        return null;
    }

    private static function pathHint(string $dir): string
    {
        $dir = rtrim($dir, '/');
        $onPath = in_array($dir, explode(PATH_SEPARATOR, (string) getenv('PATH')), true);
        return $onPath ? '' : "\n{$dir} is not on your PATH. Add this to your shell profile:\n"
            . "  export PATH=\"{$dir}:\$PATH\"";
    }
}
