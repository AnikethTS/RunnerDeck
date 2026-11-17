<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\Installer;

final class InstallerTest extends TestCase
{
    private string $root;
    private string $bin;
    private string $path;

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
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->path);
        putenv('HOME');
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
}
