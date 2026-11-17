<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\Installer;
use RunnerDeck\System\Shell;

final class InstallerTest extends TestCase
{
    private string $root;
    private string $bin;
    private string $path;
    private string $home;
    private string|false $shell;
    private string|false $xdg;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('symlinks');
        }
        $this->root = sys_get_temp_dir() . '/rd-checkout-' . uniqid();
        $this->bin = sys_get_temp_dir() . '/rd-bin-' . uniqid();
        mkdir($this->root, 0755, true);
        file_put_contents($this->root . '/runnerdeck', "#!/bin/sh\n");
        $this->path = (string) getenv('PATH');
        $this->shell = getenv('SHELL');
        $this->xdg = getenv('XDG_CONFIG_HOME');
        $this->home = sys_get_temp_dir() . '/rd-home-' . uniqid();
        mkdir($this->home, 0755, true);
        putenv('HOME=' . $this->home);
        putenv('XDG_CONFIG_HOME');
        putenv('SHELL=/bin/bash');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->path);
        putenv('HOME');
        putenv($this->shell === false ? 'SHELL' : 'SHELL=' . $this->shell);
        putenv($this->xdg === false ? 'XDG_CONFIG_HOME' : 'XDG_CONFIG_HOME=' . $this->xdg);
        Shell::fake(null);
    }

    public function testInstallLinksTheScriptAndExplainsAMissingPathEntry(): void
    {
        $result = Installer::install($this->root, $this->bin);
        $this->assertTrue($result['ok']);
        $this->assertSame($this->root . '/runnerdeck', readlink($this->bin . '/runnerdeck'));
        $this->assertStringContainsString('export PATH="' . $this->bin . ':$PATH"', $result['message']);
    }

    public function testNoPathHintWhenTheFolderIsAlreadyOnPath(): void
    {
        putenv('PATH=' . $this->bin . PATH_SEPARATOR . $this->path);
        $result = Installer::install($this->root, $this->bin);
        $this->assertStringNotContainsString('not on your PATH', $result['message']);
    }

    public function testInstallingTwiceIsFine(): void
    {
        Installer::install($this->root, $this->bin);
        $again = Installer::install($this->root, $this->bin);
        $this->assertTrue($again['ok']);
        $this->assertStringContainsString('Already installed', $again['message']);
    }

    public function testAnotherFileIsNotReplacedWithoutForce(): void
    {
        mkdir($this->bin, 0755, true);
        file_put_contents($this->bin . '/runnerdeck', 'something else');
        $refused = Installer::install($this->root, $this->bin);
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('--force', $refused['message']);
        $this->assertSame('something else', file_get_contents($this->bin . '/runnerdeck'));

        $forced = Installer::install($this->root, $this->bin, true);
        $this->assertTrue($forced['ok']);
        $this->assertTrue(is_link($this->bin . '/runnerdeck'));
    }

    public function testPackagedInstallsAreTurnedAway(): void
    {
        unlink($this->root . '/runnerdeck');
        $result = Installer::install($this->root, $this->bin);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('source checkout', $result['message']);
    }

    public function testUninstallRemovesOnlyItsOwnLink(): void
    {
        $this->assertFalse(Installer::uninstall($this->root, $this->bin)['ok']);

        mkdir($this->bin, 0755, true);
        symlink('/somewhere/else', $this->bin . '/runnerdeck');
        $other = Installer::uninstall($this->root, $this->bin);
        $this->assertFalse($other['ok']);
        $this->assertStringContainsString('points somewhere else', $other['message']);
        unlink($this->bin . '/runnerdeck');

        Installer::install($this->root, $this->bin);
        $this->assertTrue(Installer::uninstall($this->root, $this->bin)['ok']);
        $this->assertFileDoesNotExist($this->bin . '/runnerdeck');
    }

    public function testDefaultDirIsUnderHome(): void
    {
        putenv('HOME=/home/someone');
        $this->assertSame('/home/someone/.local/bin', Installer::defaultDir());
    }

    public function testInstallCannotCreateAFolderUnderAFile(): void
    {
        file_put_contents($this->bin, 'a file');
        $result = Installer::install($this->root, $this->bin . '/sub');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cannot create', $result['message']);
        unlink($this->bin);
    }

    public function testWindowsIsPointedAtTheInstaller(): void
    {
        $result = Installer::install($this->root, $this->bin, false, 'Windows');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Windows installer', $result['message']);
    }

    public function testAFolderThatCannotBeWrittenIsReported(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anywhere');
        }
        mkdir($this->bin, 0555, true);
        $result = Installer::install($this->root, $this->bin);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cannot link', $result['message']);
        chmod($this->bin, 0755);
    }

    public function testPathHintUsesFishSyntaxForFish(): void
    {
        putenv('SHELL=/usr/bin/fish');
        Shell::fake(fn (array $cmd): array => ['code' => 0, 'stdout' => "/usr/bin\n/bin", 'stderr' => '']);
        $result = Installer::install($this->root, $this->bin);
        $this->assertStringContainsString('fish_add_path ' . $this->bin, $result['message']);
        $this->assertStringNotContainsString('export PATH', $result['message']);
    }

    public function testFishPathComesFromFishNotTheInheritedEnvironment(): void
    {
        putenv('SHELL=/usr/bin/fish');
        putenv('PATH=/usr/bin');
        Shell::fake(fn (array $cmd): array => [
            'code' => 0, 'stdout' => "/usr/bin\n{$this->bin}", 'stderr' => '',
        ]);
        $result = Installer::install($this->root, $this->bin);
        $this->assertStringNotContainsString('not on your PATH', $result['message']);
    }

    public function testFishFallsBackToTheEnvironmentWhenItCannotBeAsked(): void
    {
        putenv('SHELL=/usr/bin/fish');
        putenv('PATH=' . $this->bin . PATH_SEPARATOR . $this->path);
        Shell::fake(fn (array $cmd): array => ['code' => 127, 'stdout' => '', 'stderr' => 'not found']);
        $result = Installer::install($this->root, $this->bin);
        $this->assertStringNotContainsString('not on your PATH', $result['message']);
    }

    public function testFishCompletionIsWrittenToTheFishCompletionsFolder(): void
    {
        putenv('SHELL=/usr/bin/fish');
        Shell::fake(fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => '']);
        $result = Installer::install($this->root, $this->bin);
        $file = $this->home . '/.config/fish/completions/runnerdeck.fish';
        $this->assertFileExists($file);
        $this->assertStringContainsString('complete -c runnerdeck', (string) file_get_contents($file));
        $this->assertStringContainsString($file, $result['message']);
    }

    public function testFishCompletionHonoursXdgConfigHome(): void
    {
        putenv('SHELL=/usr/bin/fish');
        putenv('XDG_CONFIG_HOME=' . $this->home . '/xdg');
        Shell::fake(fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => '']);
        Installer::install($this->root, $this->bin);
        $this->assertFileExists($this->home . '/xdg/fish/completions/runnerdeck.fish');
    }

    public function testZshCompletionGoesToZfuncWithAFpathNote(): void
    {
        putenv('SHELL=/bin/zsh');
        $result = Installer::install($this->root, $this->bin);
        $this->assertFileExists($this->home . '/.zfunc/_runnerdeck');
        $this->assertStringContainsString('fpath=(~/.zfunc $fpath)', $result['message']);
    }

    public function testBashCompletionIsAppendedToBashrcOnlyOnce(): void
    {
        file_put_contents($this->home . '/.bashrc', 'alias ll="ls -l"');
        Installer::install($this->root, $this->bin);
        Installer::install($this->root, $this->bin);
        $rc = (string) file_get_contents($this->home . '/.bashrc');
        $this->assertStringStartsWith("alias ll=\"ls -l\"\n", $rc);
        $this->assertSame(1, substr_count($rc, 'source <(runnerdeck completion bash)'));
    }

    public function testCompletionCanBeSkippedAndUnknownShellsAreLeftAlone(): void
    {
        Installer::install($this->root, $this->bin, false, PHP_OS_FAMILY, false);
        $this->assertFileDoesNotExist($this->home . '/.bashrc');

        putenv('SHELL=/bin/tcsh');
        $result = Installer::install($this->root, $this->bin, true);
        $this->assertStringNotContainsString('completion', $result['message']);
        $this->assertFileDoesNotExist($this->home . '/.bashrc');
    }

    public function testNoCompletionIsAttemptedWithoutAHomeFolder(): void
    {
        putenv('HOME');
        $result = Installer::install($this->root, $this->bin);
        $this->assertTrue($result['ok']);
        $this->assertStringNotContainsString('completion', $result['message']);
    }

    public function testACompletionFolderThatCannotBeCreatedIsReportedNotFatal(): void
    {
        putenv('SHELL=/bin/zsh');
        file_put_contents($this->home . '/.zfunc', 'a file, not a folder');
        $result = Installer::install($this->root, $this->bin);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('skipped zsh completion', $result['message']);
        $this->assertTrue(is_link($this->bin . '/runnerdeck'));
    }

    public function testACompletionFileThatCannotBeWrittenIsReportedNotFatal(): void
    {
        putenv('SHELL=/bin/zsh');
        mkdir($this->home . '/.zfunc/_runnerdeck', 0755, true);
        $result = Installer::install($this->root, $this->bin);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Could not write', $result['message']);
        $this->assertStringContainsString('skipped zsh completion', $result['message']);
    }

    public function testABashrcThatCannotBeWrittenIsReportedNotFatal(): void
    {
        mkdir($this->home . '/.bashrc');
        $result = Installer::install($this->root, $this->bin);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('skipped bash completion', $result['message']);
    }
}
