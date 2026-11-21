<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\Updater;
use RunnerDeck\Configuration\Config;
use RunnerDeck\System\Shell;

final class UpdaterTest extends TestCase
{
    private const NO_FACTS = ['windows' => false, 'docker' => false, 'git' => false];

    /** @var list<list<string>> */
    private array $ran = [];

    protected function tearDown(): void
    {
        Shell::fake(null);
    }

    public function testDetectMethodFromTheInstallPath(): void
    {
        $this->assertSame('deb', Updater::detectMethod('/usr/share/runnerdeck', self::NO_FACTS));
        $this->assertSame(
            'brew',
            Updater::detectMethod('/opt/homebrew/Cellar/runnerdeck/1.16.0/libexec', self::NO_FACTS)
        );
        $this->assertSame(
            'brew',
            Updater::detectMethod('/home/u/.linuxbrew/Cellar/runnerdeck/1/libexec', self::NO_FACTS)
        );
        $this->assertSame('git', Updater::detectMethod('/home/u/RunnerDeck', ['git' => true] + self::NO_FACTS));
        $this->assertSame('unknown', Updater::detectMethod('/srv/rd', self::NO_FACTS));
    }

    public function testWindowsAndDockerWinOverThePath(): void
    {
        $windows = ['windows' => true] + self::NO_FACTS;
        $this->assertSame('windows', Updater::detectMethod('C:\\Users\\me\\rt', $windows));
        $this->assertSame('docker', Updater::detectMethod('/app', ['docker' => true, 'git' => true] + self::NO_FACTS));
    }

    public function testTheOverrideWinsButOnlyForKnownMethods(): void
    {
        $this->assertSame('brew', Updater::detectMethod('/srv/rd', self::NO_FACTS, 'brew'));
        $this->assertSame('unknown', Updater::detectMethod('/srv/rd', self::NO_FACTS, 'rm -rf'));
    }

    public function testAssetNamesFollowTheReleaseLayout(): void
    {
        $this->assertSame(
            ['asset' => 'RunnerDeck-1.2.3-linux-all.deb', 'sums' => 'SHA256SUMS-linux-all'],
            Updater::assetsFor('deb', '1.2.3')
        );
        $this->assertSame(
            ['asset' => 'RunnerDeck-1.2.3-windows-x64-setup.exe', 'sums' => 'SHA256SUMS-windows-x64'],
            Updater::assetsFor('windows', '1.2.3')
        );
        $this->assertSame(
            ['asset' => 'runnerdeck-v1.2.3.zip', 'sums' => 'runnerdeck-v1.2.3.zip.sha256'],
            Updater::assetsFor('zip', '1.2.3')
        );
        $this->assertNull(Updater::assetsFor('brew', '1.2.3'));
    }

    public function testChecksumMatchesOnlyTheNamedFile(): void
    {
        $dir = $this->tmp();
        file_put_contents("$dir/a.deb", 'payload');
        $hash = hash('sha256', 'payload');

        $this->assertTrue(Updater::checksumMatches("$dir/a.deb", "$hash  a.deb\n"));
        $this->assertTrue(Updater::checksumMatches("$dir/a.deb", strtoupper($hash) . " *a.deb\n"));
        $this->assertFalse(Updater::checksumMatches("$dir/a.deb", str_repeat('0', 64) . "  a.deb\n"));
        $this->assertFalse(Updater::checksumMatches("$dir/a.deb", "$hash  other.deb\n"));
        $this->assertFalse(Updater::checksumMatches("$dir/a.deb", ''));
        $this->assertFalse(Updater::checksumMatches("$dir/missing.deb", "$hash  missing.deb\n"));
    }

    public function testUpToDateExitsZeroAndRunsNothing(): void
    {
        $this->latest(Config::version());
        [$code, $out] = $this->update(['yes' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('is up to date', $out);
        $this->assertSame([], $this->ran);
    }

    public function testCheckOnlyReportsButInstallsNothing(): void
    {
        $this->latest('99.0.0');
        [$code, $out] = $this->update(['check' => true], 'brew');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('RunnerDeck 99.0.0 is available', $out);
        $this->assertSame([], $this->ran);
    }

    public function testJsonCheckCarriesTheMethodAndVersions(): void
    {
        $this->latest('99.0.0');
        [$code, $out] = $this->update(['check' => true, 'json' => true], 'deb');
        $data = json_decode($out, true);
        $this->assertSame(0, $code);
        $this->assertTrue($data['update_available']);
        $this->assertSame('99.0.0', $data['latest']);
        $this->assertSame('deb', $data['method']);
    }

    public function testJsonUpdateWithoutYesIsRefused(): void
    {
        $this->latest('99.0.0');
        [$code, $out] = $this->update(['json' => true], 'brew');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--yes', json_decode($out, true)['message']);
        $this->assertSame([], $this->ran);
    }

    public function testACheckFailureIsReported(): void
    {
        Shell::fake(fn (array $cmd): array => ['code' => 1, 'stdout' => '', 'stderr' => 'offline']);
        [$code, , $err] = $this->update([]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not check for updates: offline', $err);
    }

    public function testDecliningThePromptChangesNothing(): void
    {
        $this->latest('99.0.0');
        [$code, $out] = $this->update([], 'brew', "n\n");
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Not updated.', $out);
        $this->assertSame([], $this->ran);
    }

    public function testNoAnswerCountsAsNo(): void
    {
        $this->latest('99.0.0');
        [, $out] = $this->update([], 'brew', '');
        $this->assertStringContainsString('Not updated.', $out);
        $this->assertSame([], $this->ran);
    }

    public function testBrewUpgradeRunsAfterYes(): void
    {
        $this->latest('99.0.0');
        [$code, $out] = $this->update([], 'brew', "y\n");
        $this->assertSame(0, $code);
        $this->assertSame([['brew', 'upgrade', 'anikethts/runnerdeck/runnerdeck']], $this->ran);
        $this->assertStringContainsString('Updated to RunnerDeck 99.0.0', $out);
    }

    public function testGitUpdateIsAFastForwardPullInTheCheckout(): void
    {
        $this->latest('99.0.0');
        $this->update(['yes' => true], 'git');
        $this->assertSame([['git', '-C', '/srv/rd', 'pull', '--ff-only']], $this->ran);
    }

    public function testAFailingCommandFailsTheUpdate(): void
    {
        $this->latest('99.0.0');
        [$code, $out, $err] = $this->update(['yes' => true], 'brew', '', 3);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('brew exited with 3', $err);
        $this->assertStringNotContainsString('Updated to', $out);
    }

    public function testDockerAndUnknownInstallsGetAdviceNotCommands(): void
    {
        $this->latest('99.0.0');
        [, $out] = $this->update(['yes' => true], 'docker');
        $this->assertStringContainsString('docker compose pull', $out);
        [, $out] = $this->update(['yes' => true], 'unknown');
        $this->assertStringContainsString('RUNNERDECK_INSTALL_METHOD', $out);
        $this->assertSame([], $this->ran);
    }

    public function testDebIsDownloadedVerifiedThenInstalled(): void
    {
        $this->latest('99.0.0', function (array $cmd): void {
            $dir = $cmd[array_search('--dir', $cmd, true) + 1];
            file_put_contents("$dir/RunnerDeck-99.0.0-linux-all.deb", 'deb-bytes');
            file_put_contents(
                "$dir/SHA256SUMS-linux-all",
                hash('sha256', 'deb-bytes') . "  RunnerDeck-99.0.0-linux-all.deb\n"
            );
        });
        [$code, $out] = $this->update(['yes' => true], 'deb');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Checksum matches.', $out);
        $this->assertSame(['sudo', 'apt-get', 'install', '-y'], array_slice($this->ran[0], 0, 4));
        $this->assertStringEndsWith('RunnerDeck-99.0.0-linux-all.deb', $this->ran[0][4]);
    }

    public function testATamperedDownloadIsNeverInstalled(): void
    {
        $this->latest('99.0.0', function (array $cmd): void {
            $dir = $cmd[array_search('--dir', $cmd, true) + 1];
            file_put_contents("$dir/RunnerDeck-99.0.0-linux-all.deb", 'evil');
            file_put_contents(
                "$dir/SHA256SUMS-linux-all",
                hash('sha256', 'good') . "  RunnerDeck-99.0.0-linux-all.deb\n"
            );
        });
        [$code, , $err] = $this->update(['yes' => true], 'deb');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('nothing was installed', $err);
        $this->assertSame([], $this->ran);
    }

    public function testAFailedDownloadIsReported(): void
    {
        $this->latest('99.0.0', null, 1);
        [$code, , $err] = $this->update(['yes' => true], 'windows');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('download failed', $err);
        $this->assertSame([], $this->ran);
    }

    public function testWithoutAnInjectedRunnerCommandsRunOnTheRealTerminal(): void
    {
        $this->latest('99.0.0');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $updater = new Updater($out, $err, fopen('php://memory', 'r'));
        $code = $updater->run(false, true, false, $this->tmp() . '/not-a-checkout', 'git');
        rewind($err);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('git exited with', (string) stream_get_contents($err));
    }

    public function testAShareFolderIsTheZipInstall(): void
    {
        $home = getenv('HOME');
        putenv('HOME=/home/someone');
        try {
            $this->assertSame(
                'zip',
                Updater::detectMethod('/home/someone/.local/share/runnerdeck', self::NO_FACTS)
            );
            $this->assertSame(
                'git',
                Updater::detectMethod('/home/someone/.local/share/runnerdeck', ['git' => true] + self::NO_FACTS)
            );
            $this->assertSame('unknown', Updater::detectMethod('/home/someone/src/RunnerDeck', self::NO_FACTS));
        } finally {
            putenv($home === false ? 'HOME' : 'HOME=' . $home);
        }
    }

    public function testZipUpdateCopiesTheReleaseAndKeepsLocalData(): void
    {
        $root = $this->tmp() . '/share';
        mkdir($root . '/storage', 0755, true);
        file_put_contents($root . '/.env', "KEEP=1\n");
        file_put_contents($root . '/storage/settings.json', '{"ok":true}');
        file_put_contents($root . '/VERSION', "2.1.0\n");

        Shell::fake(function (array $cmd): array {
            if (in_array('download', $cmd, true)) {
                $dir = $cmd[array_search('--dir', $cmd, true) + 1];
                $name = 'runnerdeck-v99.0.0.zip';
                file_put_contents($dir . '/' . $name, 'zip-bytes');
                file_put_contents(
                    $dir . '/' . $name . '.sha256',
                    hash('sha256', 'zip-bytes') . "  {$name}\n"
                );
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'unzip') {
                $dest = $cmd[array_search('-d', $cmd, true) + 1] . '/runnerdeck-v99.0.0';
                mkdir($dest, 0755, true);
                file_put_contents($dest . '/runnerdeck', "#!/bin/sh\n");
                file_put_contents($dest . '/VERSION', "99.0.0\n");
                file_put_contents($dest . '/.env', "FROMZIP=1\n");
                mkdir($dest . '/storage', 0755, true);
                file_put_contents($dest . '/storage/settings.json', '{}');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '{"tag_name":"v99.0.0"}', 'stderr' => ''];
        });

        [$code, $out] = $this->update(['yes' => true], 'zip', '', 0, $root);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Checksum matches.', $out);
        $this->assertSame('99.0.0', trim((string) file_get_contents($root . '/VERSION')));
        $this->assertSame("KEEP=1\n", (string) file_get_contents($root . '/.env'));
        $this->assertSame('{"ok":true}', (string) file_get_contents($root . '/storage/settings.json'));
        $this->assertSame([$root . '/runnerdeck', 'install', '--force'], $this->ran[0]);
    }

    public function testAZipDownloadFailureInstallsNothing(): void
    {
        $this->latest('99.0.0', null, 1);
        [$code, , $err] = $this->update(['yes' => true], 'zip');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('download failed', $err);
        $this->assertSame([], $this->ran);
    }

    public function testAFailedUnzipInstallsNothing(): void
    {
        Shell::fake(function (array $cmd): array {
            if (in_array('download', $cmd, true)) {
                $dir = $cmd[array_search('--dir', $cmd, true) + 1];
                file_put_contents($dir . '/unpacked', 'not-a-directory');
                $name = 'runnerdeck-v99.0.0.zip';
                file_put_contents($dir . '/' . $name, 'zip-bytes');
                file_put_contents($dir . '/' . $name . '.sha256', hash('sha256', 'zip-bytes') . "  {$name}\n");
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '{"tag_name":"v99.0.0"}', 'stderr' => ''];
        });
        [$code, , $err] = $this->update(['yes' => true], 'zip');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not unpack the release', $err);
        $this->assertSame([], $this->ran);

        Shell::fake(function (array $cmd): array {
            if (in_array('download', $cmd, true)) {
                $dir = $cmd[array_search('--dir', $cmd, true) + 1];
                $name = 'runnerdeck-v99.0.0.zip';
                file_put_contents($dir . '/' . $name, 'zip-bytes');
                file_put_contents($dir . '/' . $name . '.sha256', hash('sha256', 'zip-bytes') . "  {$name}\n");
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'unzip') {
                return ['code' => 1, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '{"tag_name":"v99.0.0"}', 'stderr' => ''];
        });
        [$code, , $err] = $this->update(['yes' => true], 'zip');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unzip failed', $err);
    }

    public function testAZipThatCannotBeCopiedInstallsNothing(): void
    {
        $parent = $this->tmp();
        file_put_contents($parent . '/blocked', 'x');
        Shell::fake(function (array $cmd): array {
            if (in_array('download', $cmd, true)) {
                $dir = $cmd[array_search('--dir', $cmd, true) + 1];
                $name = 'runnerdeck-v99.0.0.zip';
                file_put_contents($dir . '/' . $name, 'zip-bytes');
                file_put_contents($dir . '/' . $name . '.sha256', hash('sha256', 'zip-bytes') . "  {$name}\n");
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'unzip') {
                $src = $cmd[array_search('-d', $cmd, true) + 1] . '/runnerdeck-v99.0.0';
                mkdir($src, 0755, true);
                file_put_contents($src . '/runnerdeck', "#!/bin/sh\n");
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '{"tag_name":"v99.0.0"}', 'stderr' => ''];
        });
        [$code, , $err] = $this->update(['yes' => true], 'zip', '', 0, $parent . '/blocked/runnerdeck');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not copy the new release', $err);
        $this->assertSame([], $this->ran);
    }

    public function testCopyOverKeepsNestedFilesAndReportsFailures(): void
    {
        $src = $this->tmp() . '/src';
        $dest = $this->tmp() . '/dest';
        mkdir($src . '/app', 0755, true);
        file_put_contents($src . '/app/cli.php', '<?php ');
        mkdir($dest, 0755, true);
        $this->assertTrue(Updater::copyOver($src, $dest));
        $this->assertSame('<?php ', (string) file_get_contents($dest . '/app/cli.php'));

        $this->assertFalse(Updater::copyOver($src . '/app/cli.php', $dest));

        $blocked = $this->tmp() . '/file';
        file_put_contents($blocked, 'x');
        $this->assertFalse(Updater::copyOver($src, $blocked . '/child'));

        mkdir($src . '/locked', 0755, true);
        chmod($src . '/locked', 0000);
        try {
            $this->assertFalse(Updater::copyOver($src . '/locked', $dest . '/locked'));
        } finally {
            chmod($src . '/locked', 0755);
        }

        $destFile = $this->tmp() . '/dest-file';
        mkdir($destFile, 0755, true);
        file_put_contents($destFile . '/app', 'was-a-file');
        $nested = $this->tmp() . '/nested-src';
        mkdir($nested . '/app', 0755, true);
        file_put_contents($nested . '/app/inside', 'y');
        $this->assertFalse(Updater::copyOver($nested, $destFile));

        mkdir($dest . '/app-file', 0755, true);
        file_put_contents($src . '/app-file', 'file');
        $this->assertFalse(Updater::copyOver($src, $dest));

        $held = $this->tmp() . '/held';
        $heldSrc = $this->tmp() . '/held-src';
        mkdir($held, 0755, true);
        mkdir($heldSrc, 0755, true);
        file_put_contents($held . '/a.txt', 'old');
        file_put_contents($heldSrc . '/a.txt', 'new');
        chmod($held, 0555);
        try {
            $this->assertFalse(Updater::copyOver($heldSrc, $held));
        } finally {
            chmod($held, 0755);
        }

        $unreadable = $this->tmp() . '/secret-src';
        $plain = $this->tmp() . '/plain-dest';
        mkdir($unreadable, 0755, true);
        mkdir($plain, 0755, true);
        file_put_contents($unreadable . '/secret', 'x');
        chmod($unreadable . '/secret', 0000);
        try {
            $this->assertFalse(Updater::copyOver($unreadable, $plain));
        } finally {
            chmod($unreadable . '/secret', 0644);
        }
    }

    public function testAnEmptyHomeIsNotAZipInstall(): void
    {
        $home = getenv('HOME');
        putenv('HOME=');
        try {
            $this->assertSame('unknown', Updater::detectMethod('/.local/share/runnerdeck', self::NO_FACTS));
        } finally {
            putenv($home === false ? 'HOME' : 'HOME=' . $home);
        }
    }

    public function testRemoveDirIgnoresAMissingPath(): void
    {
        $file = $this->tmp() . '/plain-file';
        file_put_contents($file, 'keep');
        $updater = new Updater(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'), fopen('php://memory', 'r'));
        $remove = new \ReflectionMethod($updater, 'removeDir');
        $remove->invoke($updater, $file);
        $this->assertSame('keep', (string) file_get_contents($file));
    }

    public function testAZipWithTheWrongLayoutInstallsNothing(): void
    {
        Shell::fake(function (array $cmd): array {
            if (in_array('download', $cmd, true)) {
                $dir = $cmd[array_search('--dir', $cmd, true) + 1];
                $name = 'runnerdeck-v99.0.0.zip';
                file_put_contents($dir . '/' . $name, 'zip-bytes');
                file_put_contents($dir . '/' . $name . '.sha256', hash('sha256', 'zip-bytes') . "  {$name}\n");
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'unzip') {
                $dest = $cmd[array_search('-d', $cmd, true) + 1];
                mkdir($dest . '/other', 0755, true);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 0, 'stdout' => '{"tag_name":"v99.0.0"}', 'stderr' => ''];
        });

        [$code, , $err] = $this->update(['yes' => true], 'zip');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unexpected archive layout', $err);
        $this->assertSame([], $this->ran);
    }

    public function testApplyRefusesInstallTypesWithoutADownload(): void
    {
        $err = fopen('php://memory', 'w+');
        $updater = new Updater(fopen('php://memory', 'w+'), $err, fopen('php://memory', 'r'));
        $apply = new \ReflectionMethod($updater, 'apply');
        $this->assertSame(1, $apply->invoke($updater, 'unknown', '9.9.9', '/srv/rd'));
        rewind($err);
        $text = (string) stream_get_contents($err);
        $this->assertStringContainsString('cannot update an install of type unknown', $text);
    }

    private function tmp(): string
    {
        $dir = sys_get_temp_dir() . '/runnerdeck-updater-' . uniqid();
        mkdir($dir, 0700, true);
        return $dir;
    }

    /** @param null|callable(list<string>): void $onDownload */
    private function latest(string $version, ?callable $onDownload = null, int $downloadCode = 0): void
    {
        Shell::fake(function (array $cmd) use ($version, $onDownload, $downloadCode): array {
            if (in_array('release', $cmd, true) && in_array('download', $cmd, true)) {
                if ($onDownload !== null && $downloadCode === 0) {
                    $onDownload($cmd);
                }
                return ['code' => $downloadCode, 'stdout' => '', 'stderr' => $downloadCode ? 'HTTP 404' : ''];
            }
            return ['code' => 0, 'stdout' => json_encode(['tag_name' => "v{$version}"]), 'stderr' => ''];
        });
    }

    /**
     * @param array<string, mixed> $flags check, yes, json
     * @return array{0: int, 1: string, 2: string}
     */
    private function update(
        array $flags,
        string $method = 'brew',
        string $input = '',
        int $commandCode = 0,
        string $root = '/srv/rd'
    ): array {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);
        $this->ran = [];
        $updater = new Updater($out, $err, $in, function (array $cmd) use ($commandCode): int {
            $this->ran[] = $cmd;
            return $commandCode;
        });
        $code = $updater->run(
            isset($flags['check']),
            isset($flags['yes']),
            isset($flags['json']),
            $root,
            $method
        );
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
