<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

use RunnerDeck\System\Shell;

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
        string $os = PHP_OS_FAMILY,
        bool $completions = true
    ): array {
        $script = $root . '/runnerdeck';
        $blocked = self::unsupported($root, $os);
        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked];
        }
        $link = rtrim($dir, '/') . '/runnerdeck';
        if (is_link($link) && readlink($link) === $script) {
            return ['ok' => true, 'message' => "Already installed: {$link}" . self::pathHint($dir)
                . ($completions ? self::wireCompletion() : '')];
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
        return ['ok' => true, 'message' => "Installed: {$link} -> {$script}" . self::pathHint($dir)
            . ($completions ? self::wireCompletion() : '')];
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

    /** The login shell's name (bash, zsh, fish, ...) from $SHELL, or '' when unknown. */
    public static function shellName(): string
    {
        return basename((string) getenv('SHELL'));
    }

    /** The command that puts $dir on PATH, in the syntax of the user's shell. */
    public static function pathCommand(string $dir, string $shell): string
    {
        return $shell === 'fish'
            ? "fish_add_path {$dir}"
            : "export PATH=\"{$dir}:\$PATH\"";
    }

    private static function pathHint(string $dir): string
    {
        $dir = rtrim($dir, '/');
        $shell = self::shellName();
        if (in_array($dir, self::shellPath($shell), true)) {
            return '';
        }
        $where = $shell === 'fish' ? 'Run this once (fish remembers it)' : 'Add this to your shell profile';
        return "\n{$dir} is not on your PATH. {$where}:\n  " . self::pathCommand($dir, $shell);
    }

    /**
     * PATH entries as the user's shell sees them. fish keeps ~/.local/bin in the
     * fish_user_paths universal variable, which this process may not have inherited,
     * so ask fish itself and fall back to our own environment.
     *
     * @return list<string>
     */
    private static function shellPath(string $shell): array
    {
        if ($shell === 'fish') {
            $fish = (string) getenv('SHELL');
            $res = Shell::exec([$fish, '-c', 'printf "%s\n" $PATH'], 5);
            if ($res['code'] === 0 && trim($res['stdout']) !== '') {
                return self::split(trim($res['stdout']), "\n");
            }
        }
        return self::split((string) getenv('PATH'), PATH_SEPARATOR);
    }

    /**
     * @param non-empty-string $sep
     * @return list<string>
     */
    private static function split(string $list, string $sep): array
    {
        return array_map(static fn (string $d): string => rtrim($d, '/'), explode($sep, $list));
    }

    /**
     * Put tab completion in the place the user's shell reads it. Files runnerdeck
     * owns are rewritten; ~/.bashrc only ever gets one marked line appended.
     */
    private static function wireCompletion(): string
    {
        $shell = self::shellName();
        $home = (string) getenv('HOME');
        if ($home === '' || !in_array($shell, ['fish', 'bash', 'zsh'], true)) {
            return '';
        }
        if ($shell === 'bash') {
            return self::wireBash($home . '/.bashrc');
        }
        if ($shell === 'fish') {
            $config = (string) getenv('XDG_CONFIG_HOME');
            $file = ($config !== '' ? $config : $home . '/.config') . '/fish/completions/runnerdeck.fish';
        } else {
            $file = $home . '/.zfunc/_runnerdeck';
        }
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) {
            return "\nCould not create " . dirname($file) . "; skipped {$shell} completion.";
        }
        if (@file_put_contents($file, (string) Completion::script($shell)) === false) {
            return "\nCould not write {$file}; skipped {$shell} completion.";
        }
        $note = "\nInstalled {$shell} completion: {$file}";
        if ($shell === 'zsh') {
            $note .= "\nAdd this to ~/.zshrc before compinit if ~/.zfunc is not on your fpath:\n"
                . '  fpath=(~/.zfunc $fpath)';
        }
        return $note;
    }

    private static function wireBash(string $rc): string
    {
        $marker = '# runnerdeck completion';
        $line = 'source <(runnerdeck completion bash)';
        $current = is_file($rc) ? (string) file_get_contents($rc) : '';
        if (str_contains($current, $marker)) {
            return "\nbash completion is already set up in {$rc}";
        }
        $prefix = $current !== '' && !str_ends_with($current, "\n") ? "\n" : '';
        if (@file_put_contents($rc, "{$prefix}{$marker}\n{$line}\n", FILE_APPEND) === false) {
            return "\nCould not write {$rc}; skipped bash completion.";
        }
        return "\nAdded bash completion to {$rc}. Open a new terminal to use it.";
    }
}
