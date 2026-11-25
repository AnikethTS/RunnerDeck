<?php

declare(strict_types=1);

namespace RunnerDeck\Cli;

final class Completion
{
    public const SHELLS = ['bash', 'zsh', 'fish', 'powershell'];

    public const COMMANDS = [
        'run', 'list', 'ls', 'status', 'start', 'stop', 'restart', 'rolling-restart', 'drain', 'resize',
        'rename', 'force-idle', 'maintenance', 'add', 'delete', 'rm', 'logs', 'config', 'setup', 'auth',
        'update', 'upgrade', 'doctor', 'install', 'uninstall', 'completion', 'version', 'help',
    ];

    private const RUNNER_COMMANDS = [
        'start', 'stop', 'restart', 'rolling-restart', 'drain', 'rename', 'force-idle', 'delete', 'rm', 'logs',
    ];

    private const SUBCOMMANDS = [
        'config' => ['list', 'get', 'set', 'unset'],
        'auth' => ['status', 'login', 'totp'],
        'maintenance' => ['on', 'off', 'status'],
        'completion' => self::SHELLS,
    ];

    private const FLAGS = [
        '--all', '--force', '--dry-run', '--json', '--yes', '--quiet', '--no-color', '--timeout', '--help',
    ];

    private const LIST_COMMANDS = ['list', 'ls', 'status'];

    public static function script(string $shell): ?string
    {
        return match ($shell) {
            'bash' => self::bash(),
            'zsh' => self::zsh(),
            'fish' => self::fish(),
            'powershell' => self::powershell(),
            default => null,
        };
    }

    private static function bash(): string
    {
        $commands = implode(' ', self::COMMANDS);
        $runner = implode('|', self::RUNNER_COMMANDS);
        $list = implode('|', self::LIST_COMMANDS);
        $flags = implode(' ', self::FLAGS);
        $cases = '';
        foreach (self::SUBCOMMANDS as $cmd => $subs) {
            $cases .= "        {$cmd}) words=\"" . implode(' ', $subs) . "\" ;;\n";
        }
        return <<<BASH
# bash completion for runnerdeck. Add to ~/.bashrc:  source <(runnerdeck completion bash)
_runnerdeck() {
    local cur words
    cur="\${COMP_WORDS[COMP_CWORD]}"
    if [ "\$COMP_CWORD" -eq 1 ]; then
        COMPREPLY=( \$(compgen -W "{$commands} --version --help" -- "\$cur") )
        return
    fi
    case "\${COMP_WORDS[1]}" in
{$cases}        {$list}) words="\$(runnerdeck __complete labels 2>/dev/null) --label --watch --json --help" ;;
        {$runner}) words="\$(runnerdeck __complete runners 2>/dev/null) {$flags}" ;;
        *) words="{$flags}" ;;
    esac
    if [ "\${COMP_WORDS[1]}" = "config" ] && [ "\$COMP_CWORD" -ge 3 ]; then
        words="\$(runnerdeck __complete settings 2>/dev/null)"
    fi
    COMPREPLY=( \$(compgen -W "\$words" -- "\$cur") )
}
complete -F _runnerdeck runnerdeck

BASH;
    }

    private static function zsh(): string
    {
        $commands = implode(' ', self::COMMANDS);
        $runner = implode('|', self::RUNNER_COMMANDS);
        $list = implode('|', self::LIST_COMMANDS);
        $flags = implode(' ', self::FLAGS);
        $cases = '';
        foreach (self::SUBCOMMANDS as $cmd => $subs) {
            $cases .= "        {$cmd}) words=(" . implode(' ', $subs) . ") ;;\n";
        }
        return <<<ZSH
#compdef runnerdeck
# zsh completion for runnerdeck. Put it on your fpath as _runnerdeck, or:
#   source <(runnerdeck completion zsh)
_runnerdeck() {
    local -a words_out
    if (( CURRENT == 2 )); then
        words_out=({$commands} --version --help)
    else
        case "\${words[2]}" in
{$cases}            {$list}) words_out=(
                \${(f)"\$(runnerdeck __complete labels 2>/dev/null)"} --label --watch --json --help
            ) ;;
            {$runner}) words_out=(\${(f)"\$(runnerdeck __complete runners 2>/dev/null)"} {$flags}) ;;
            *) words_out=({$flags}) ;;
        esac
        if [[ "\${words[2]}" == config ]] && (( CURRENT >= 4 )); then
            words_out=(\${(f)"\$(runnerdeck __complete settings 2>/dev/null)"})
        fi
    fi
    compadd -a words_out
}
compdef _runnerdeck runnerdeck

ZSH;
    }

    private static function fish(): string
    {
        $out = "# fish completion for runnerdeck. Save as ~/.config/fish/completions/runnerdeck.fish\n"
            . "# or: runnerdeck completion fish | source\n"
            . "complete -c runnerdeck -f\n";
        $out .= 'complete -c runnerdeck -n __fish_use_subcommand -a "' . implode(' ', self::COMMANDS) . "\"\n";
        foreach (self::SUBCOMMANDS as $cmd => $subs) {
            $out .= "complete -c runnerdeck -n \"__fish_seen_subcommand_from {$cmd}\" -a \""
                . implode(' ', $subs) . "\"\n";
        }
        $runner = implode(' ', self::RUNNER_COMMANDS);
        $list = implode(' ', self::LIST_COMMANDS);
        $out .= "complete -c runnerdeck -n \"__fish_seen_subcommand_from {$list}\" "
            . "-a \"(runnerdeck __complete labels 2>/dev/null)\"\n";
        $out .= "complete -c runnerdeck -n \"__fish_seen_subcommand_from {$list}\" -l label\n";
        $out .= "complete -c runnerdeck -n \"__fish_seen_subcommand_from {$runner}\" "
            . "-a \"(runnerdeck __complete runners 2>/dev/null)\"\n";
        $out .= "complete -c runnerdeck -n \"__fish_seen_subcommand_from config\" "
            . "-a \"(runnerdeck __complete settings 2>/dev/null)\"\n";
        foreach (self::FLAGS as $flag) {
            $out .= 'complete -c runnerdeck -l ' . substr($flag, 2) . "\n";
        }
        return $out;
    }

    private static function powershell(): string
    {
        $commands = "'" . implode("','", self::COMMANDS) . "'";
        $runner = "'" . implode("','", self::RUNNER_COMMANDS) . "'";
        $listed = "'" . implode("','", self::LIST_COMMANDS) . "'";
        $flags = "'" . implode("','", self::FLAGS) . "'";
        $subs = '';
        foreach (self::SUBCOMMANDS as $cmd => $list) {
            $subs .= "    '{$cmd}' = @('" . implode("','", $list) . "')\n";
        }
        return <<<PS
# PowerShell completion for runnerdeck. Add to your \$PROFILE:
#   runnerdeck completion powershell | Out-String | Invoke-Expression
Register-ArgumentCompleter -Native -CommandName runnerdeck -ScriptBlock {
    param(\$wordToComplete, \$commandAst, \$cursorPosition)
    \$words = \$commandAst.CommandElements | ForEach-Object { \$_.ToString() }
    \$subs = @{
{$subs}    }
    if (\$words.Count -le 1 -or (\$words.Count -eq 2 -and \$wordToComplete -ne '')) {
        \$candidates = @({$commands})
    } elseif (\$words[1] -eq 'config' -and \$words.Count -ge 3) {
        \$candidates = @(runnerdeck __complete settings)
    } elseif (\$subs.ContainsKey(\$words[1])) {
        \$candidates = \$subs[\$words[1]]
    } elseif (@({$listed}) -contains \$words[1]) {
        \$candidates = @(runnerdeck __complete labels) + @('--label','--watch','--json','--help')
    } elseif (@({$runner}) -contains \$words[1]) {
        \$candidates = @(runnerdeck __complete runners) + @({$flags})
    } else {
        \$candidates = @({$flags})
    }
    \$candidates | Where-Object { \$_ -like "\$wordToComplete*" } |
        ForEach-Object { [System.Management.Automation.CompletionResult]::new(\$_, \$_, 'ParameterValue', \$_) }
}

PS;
    }
}
