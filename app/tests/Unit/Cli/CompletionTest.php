<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\Completion;

final class CompletionTest extends TestCase
{
    public function testEveryShellKnowsEveryCommand(): void
    {
        foreach (Completion::SHELLS as $shell) {
            $script = (string) Completion::script($shell);
            foreach (Completion::COMMANDS as $command) {
                $this->assertStringContainsString($command, $script, "{$shell} lacks {$command}");
            }
            $this->assertStringContainsString('__complete runners', $script);
            $this->assertStringContainsString('__complete settings', $script);
        }
    }

    public function testUnknownShellGivesNothing(): void
    {
        $this->assertNull(Completion::script('tcsh'));
    }

    public function testSubcommandsAreOffered(): void
    {
        $bash = (string) Completion::script('bash');
        $this->assertStringContainsString('words="list get set unset"', $bash);
        $this->assertStringContainsString('words="on off status"', $bash);
        $this->assertStringContainsString('words="bash zsh fish powershell"', $bash);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function syntaxCheckers(): array
    {
        return [
            'bash' => ['bash', ['bash', '-n']],
            'zsh' => ['zsh', ['zsh', '-n']],
            'fish' => ['fish', ['fish', '--no-execute']],
        ];
    }

    /**
     * @dataProvider syntaxCheckers
     * @param list<string> $checker
     */
    public function testScriptsParseInTheirShell(string $shell, array $checker): void
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($checker[0]) . ' 2>/dev/null'));
        if ($path === '') {
            $this->markTestSkipped("{$shell} is not installed");
        }
        $file = tempnam(sys_get_temp_dir(), 'rd-completion-');
        file_put_contents($file, Completion::script($shell));
        exec(implode(' ', array_map('escapeshellarg', [...$checker, $file])) . ' 2>&1', $output, $code);
        unlink($file);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
