<?php

declare(strict_types=1);

namespace Tests;

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
    private function update(array $flags, string $method = 'brew', string $input = '', int $commandCode = 0): array
    {
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
            '/srv/rd',
            $method
        );
        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
