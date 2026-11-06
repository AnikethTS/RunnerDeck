<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProvisionerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-prov-test-' . uniqid();
        mkdir($this->dir, 0755, true);
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_LABEL=self-hosted-runnerdeck');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/storage/settings.json');
        putenv('RUNNERDECK_AGENT_SHA');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\System\Shell::fake(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_LABEL');
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_AGENT_SHA');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, mixed> */
    private function packageMeta(?string $sha = 'abc'): array
    {
        $meta = [
            'download_url' => 'https://example.test/runner.tar.gz',
            'filename' => 'actions-runner-linux-x64.tar.gz',
        ];
        if ($sha !== null) {
            $meta['sha256_checksum'] = $sha;
        }
        return $meta;
    }

    /** @return list<array<string, mixed>> every GitHub os/arch pair so pickDownload works on CI hosts */
    private function downloadsForAnyHost(?string $sha = 'abc'): array
    {
        $meta = $this->packageMeta($sha);
        $out = [];
        foreach (['linux', 'osx', 'win'] as $os) {
            foreach (['x64', 'arm64', 'arm'] as $arch) {
                $out[] = $meta + ['os' => $os, 'architecture' => $arch];
            }
        }
        return $out;
    }

    private function downloadsJson(?string $sha): string
    {
        $encoded = json_encode($this->downloadsForAnyHost($sha));
        return is_string($encoded) ? $encoded : '[]';
    }

    /** @param list<string> $cmd */
    private function plantPackage(array $cmd): void
    {
        $flag = in_array('-C', $cmd, true) ? '-C' : '-d';
        $at = array_search($flag, $cmd, true);
        $dest = is_int($at) ? (string) $cmd[$at + 1] : $this->dir;
        if (!is_dir($dest . '/bin')) {
            mkdir($dest . '/bin', 0755, true);
        }
        file_put_contents($dest . '/config.sh', '#!/bin/sh');
        file_put_contents($dest . '/run.sh', '#!/bin/sh');
        file_put_contents($dest . '/bin/Runner.Listener', 'listener');
    }

    private function markInstalled(): void
    {
        if (!is_dir($this->dir . '/bin')) {
            mkdir($this->dir . '/bin', 0755, true);
        }
        file_put_contents($this->dir . '/config.sh', '#!/bin/sh');
        file_put_contents($this->dir . '/bin/Runner.Listener', 'listener');
    }

    public function testChecksumMatchesWhenExpectedIsEmpty(): void
    {
        $path = $this->dir . '/pkg.bin';
        file_put_contents($path, 'payload');
        $this->assertTrue(\RunnerDeck\Pool\Provisioner::checksumMatches($path, null));
        $this->assertTrue(\RunnerDeck\Pool\Provisioner::checksumMatches($path, ''));
    }

    public function testChecksumMatchesComparesSha256(): void
    {
        $path = $this->dir . '/pkg.bin';
        file_put_contents($path, 'payload');
        $sha = hash('sha256', 'payload');

        $this->assertTrue(\RunnerDeck\Pool\Provisioner::checksumMatches($path, $sha));
        $this->assertTrue(\RunnerDeck\Pool\Provisioner::checksumMatches($path, strtoupper($sha)));
        $this->assertFalse(\RunnerDeck\Pool\Provisioner::checksumMatches($path, str_repeat('0', 64)));
    }

    public function testIsZipArchive(): void
    {
        $this->assertTrue(\RunnerDeck\Pool\Provisioner::isZipArchive('actions-runner-win-x64.zip'));
        $this->assertFalse(\RunnerDeck\Pool\Provisioner::isZipArchive('actions-runner-linux-x64.tar.gz'));
    }

    public function testMatchDownloadPicksOsAndArch(): void
    {
        $downloads = [
            ['os' => 'osx', 'architecture' => 'arm64', 'filename' => 'osx.tar.gz'],
            $this->packageMeta() + ['os' => 'linux', 'architecture' => 'x64'],
        ];

        $picked = \RunnerDeck\Pool\Provisioner::matchDownload($downloads, 'Linux', 'x86_64');

        $this->assertSame('actions-runner-linux-x64.tar.gz', $picked['filename']);
    }

    public function testMatchDownloadAcceptsWindowsMachineNames(): void
    {
        $x64 = ['os' => 'win', 'architecture' => 'x64', 'filename' => 'win-x64.zip'];
        $arm = ['os' => 'win', 'architecture' => 'arm64', 'filename' => 'win-arm64.zip'];

        $pick = fn (string $machine): string => \RunnerDeck\Pool\Provisioner::matchDownload(
            [$arm, $x64],
            'Windows',
            $machine
        )['filename'];

        $this->assertSame('win-x64.zip', $pick('AMD64'));
        $this->assertSame('win-arm64.zip', $pick('ARM64'));
    }

    public function testMatchDownloadMapsLinuxArmAndDarwinAndWindows(): void
    {
        $linuxArm = ['os' => 'linux', 'architecture' => 'arm64', 'filename' => 'linux-arm64.tar.gz'];
        $osx = ['os' => 'osx', 'architecture' => 'x64', 'filename' => 'osx-x64.tar.gz'];
        $win = ['os' => 'win', 'architecture' => 'x64', 'filename' => 'win-x64.zip'];
        $linuxArmv7 = ['os' => 'linux', 'architecture' => 'arm', 'filename' => 'linux-arm.tar.gz'];

        $this->assertSame(
            'linux-arm64.tar.gz',
            \RunnerDeck\Pool\Provisioner::matchDownload([$linuxArm], 'Linux', 'aarch64')['filename']
        );
        $this->assertSame(
            'osx-x64.tar.gz',
            \RunnerDeck\Pool\Provisioner::matchDownload([$osx], 'Darwin', 'amd64')['filename']
        );
        $this->assertSame(
            'win-x64.zip',
            \RunnerDeck\Pool\Provisioner::matchDownload([$win], 'Windows', 'x86_64')['filename']
        );
        $this->assertSame(
            'linux-arm.tar.gz',
            \RunnerDeck\Pool\Provisioner::matchDownload([$linuxArmv7], 'Linux', 'armv7l')['filename']
        );
    }

    public function testMatchDownloadRejectsUnknownOs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported OS');
        \RunnerDeck\Pool\Provisioner::matchDownload([], 'Solaris', 'x86_64');
    }

    public function testMatchDownloadRejectsUnknownArch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported architecture');
        \RunnerDeck\Pool\Provisioner::matchDownload([], 'Linux', 'riscv64');
    }

    public function testMatchDownloadRejectsMissingPackage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no official runner package found for linux/x64');
        \RunnerDeck\Pool\Provisioner::matchDownload(
            [['os' => 'osx', 'architecture' => 'x64', 'filename' => 'osx.tar.gz']],
            'Linux',
            'x86_64'
        );
    }

    public function testDeregisterRemovesRegistrationFiles(): void
    {
        foreach (['.runner', '.credentials', '.credentials_rsaparams', 'run.sh'] as $file) {
            file_put_contents($this->dir . '/' . $file, 'x');
        }

        \RunnerDeck\Pool\Provisioner::deregister($this->dir);

        $this->assertFileDoesNotExist($this->dir . '/.runner');
        $this->assertFileDoesNotExist($this->dir . '/.credentials');
        $this->assertFileDoesNotExist($this->dir . '/.credentials_rsaparams');
        $this->assertFileExists($this->dir . '/run.sh');
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledSkipsWhenBinariesPresent(): void
    {
        $this->markInstalled();
        $called = false;
        \RunnerDeck\System\Shell::fake(function () use (&$called): array {
            $called = true;
            return ['code' => 1, 'stdout' => '', 'stderr' => 'should not run'];
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertSame('runner binaries already present', $result['message']);
        $this->assertFalse($called);
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledAbortsOnChecksumMismatch(): void
    {
        $payload = 'corrupt-archive';
        \RunnerDeck\System\Shell::fake(function (array $cmd) use ($payload): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode($this->downloadsForAnyHost(hash('sha256', 'expected-bytes'))),
                    'stderr' => '',
                ];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, $payload);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'downloaded runner package failed checksum verification — aborted',
            $result['message']
        );
        $this->assertFileDoesNotExist($this->dir . '/actions-runner-linux-x64.tar.gz');
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledExtractsWhenChecksumMatches(): void
    {
        $payload = 'good-archive';
        $sha = hash('sha256', $payload);
        \RunnerDeck\System\Shell::fake(function (array $cmd) use ($payload, $sha): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode($this->downloadsForAnyHost($sha)),
                    'stderr' => '',
                ];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, $payload);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'tar') {
                $this->plantPackage($cmd);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'chmod') {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('runner binaries installed', $result['message']);
        $this->assertFileExists($this->dir . '/config.sh');
        $this->assertFileDoesNotExist($this->dir . '/actions-runner-linux-x64.tar.gz');
    }

    #[RunInSeparateProcess]
    public function testInstallOsDependenciesNoopsWhenScriptMissing(): void
    {
        $result = \RunnerDeck\Pool\Provisioner::installOsDependencies($this->dir);
        $this->assertTrue($result['ok']);
        $this->assertSame('no OS dependency script', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testInstallOsDependenciesSkipsOnWindows(): void
    {
        \RunnerDeck\System\Platform::fakeWindows(true);
        mkdir($this->dir . '/bin', 0755, true);
        file_put_contents($this->dir . '/bin/installdependencies.sh', "#!/bin/sh\n");
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            self::fail('nothing should run on Windows: ' . implode(' ', $cmd));
        });

        $result = \RunnerDeck\Pool\Provisioner::installOsDependencies($this->dir);

        \RunnerDeck\System\Platform::fakeWindows(null);
        $this->assertTrue($result['ok']);
    }

    #[RunInSeparateProcess]
    public function testInstallOsDependenciesRunsGithubScript(): void
    {
        mkdir($this->dir . '/bin', 0755, true);
        file_put_contents($this->dir . '/bin/installdependencies.sh', "#!/bin/sh\ntrue\n");
        $ran = false;
        \RunnerDeck\System\Shell::fake(function (array $cmd) use (&$ran): array {
            if (($cmd[0] ?? '') === 'chmod') {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (in_array('bash', $cmd, true) && str_contains(implode(' ', $cmd), 'installdependencies.sh')) {
                $ran = true;
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = \RunnerDeck\Pool\Provisioner::installOsDependencies($this->dir);

        $this->assertTrue($result['ok']);
        if (is_file('/etc/alpine-release') || PHP_OS_FAMILY === 'Darwin') {
            $this->assertFalse($ran);
            return;
        }
        $this->assertTrue($ran);
        $this->assertSame('runner OS libraries installed', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testInstallOsDependenciesFailsWithLibraryHint(): void
    {
        mkdir($this->dir . '/bin', 0755, true);
        file_put_contents($this->dir . '/bin/installdependencies.sh', "#!/bin/sh\n");
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'chmod') {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (in_array('bash', $cmd, true)) {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'E: Unable to locate package libicu'];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = \RunnerDeck\Pool\Provisioner::installOsDependencies($this->dir);

        if (is_file('/etc/alpine-release') || PHP_OS_FAMILY === 'Darwin') {
            $this->assertTrue($result['ok']);
            return;
        }
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('libicu', $result['message']);
        $this->assertStringContainsString('will not start', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testEnsureConfiguredSkipsWhenAlreadyRegistered(): void
    {
        $this->markInstalled();
        file_put_contents($this->dir . '/.runner', '{}');

        $result = \RunnerDeck\Pool\Provisioner::ensureConfigured($this->dir, 'acme-1');

        $this->assertTrue($result['ok']);
        $this->assertSame('acme-1 already configured', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testEnsureConfiguredFailsWhenConfigShFails(): void
    {
        $this->markInstalled();
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'registration-token')) {
                return ['code' => 0, 'stdout' => "ghs_test\n", 'stderr' => ''];
            }
            if (str_ends_with((string) ($cmd[0] ?? ''), '/config.sh')) {
                return ['code' => 1, 'stdout' => 'nope', 'stderr' => 'config failed'];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureConfigured($this->dir, 'acme-1');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('config.sh failed for acme-1', $result['message']);
        $this->assertStringContainsString('config failed', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testEnsureConfiguredRunsConfigShWithTokenAndLabels(): void
    {
        $this->markInstalled();
        $configCmd = [];
        \RunnerDeck\System\Shell::fake(function (array $cmd) use (&$configCmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'registration-token')) {
                return ['code' => 0, 'stdout' => "ghs_test\n", 'stderr' => ''];
            }
            if (str_ends_with((string) ($cmd[0] ?? ''), '/config.sh')) {
                $configCmd = $cmd;
                file_put_contents($this->dir . '/.runner', '{"agentName":"acme-1"}');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureConfigured($this->dir, 'acme-1');

        $this->assertTrue($result['ok']);
        $this->assertSame('acme-1 configured', $result['message']);
        $this->assertContains('ghs_test', $configCmd);
        $this->assertContains('https://github.com/acme', $configCmd);
        $this->assertContains('self-hosted-runnerdeck', $configCmd);
        $this->assertContains('--unattended', $configCmd);
        $this->assertFileExists($this->dir . '/.runner');
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledReportsDownloadFailure(): void
    {
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return ['code' => 0, 'stdout' => json_encode($this->downloadsForAnyHost()), 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'curl') {
                return ['code' => 22, 'stdout' => '', 'stderr' => '404'];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed to download runner package', $result['message']);
        $this->assertStringContainsString('404', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledAbortsWhenListedShaDiffersFromPin(): void
    {
        putenv('RUNNERDECK_AGENT_SHA=' . str_repeat('a', 64));
        $calledCurl = false;
        \RunnerDeck\System\Shell::fake(function (array $cmd) use (&$calledCurl): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode($this->downloadsForAnyHost(str_repeat('b', 64))),
                    'stderr' => '',
                ];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $calledCurl = true;
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('RUNNERDECK_AGENT_SHA', $result['message']);
        $this->assertFalse($calledCurl);
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledChecksPinOnFileWhenGithubOmitsChecksum(): void
    {
        $payload = 'good-archive';
        $pin = hash('sha256', $payload);
        putenv('RUNNERDECK_AGENT_SHA=' . $pin);
        \RunnerDeck\System\Shell::fake(function (array $cmd) use ($payload): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode($this->downloadsForAnyHost(null)),
                    'stderr' => '',
                ];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, $payload);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'tar') {
                $this->plantPackage($cmd);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'chmod') {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertFileExists($this->dir . '/config.sh');
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledAbortsWhenOmittedChecksumFileMissesPin(): void
    {
        putenv('RUNNERDECK_AGENT_SHA=' . str_repeat('c', 64));
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return [
                    'code' => 0,
                    'stdout' => json_encode($this->downloadsForAnyHost(null)),
                    'stderr' => '',
                ];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, 'other-bytes');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not match RUNNERDECK_AGENT_SHA', $result['message']);
        $this->assertFileDoesNotExist($this->dir . '/actions-runner-linux-x64.tar.gz');
    }

    public function testVersionFromDownloadReadsFilename(): void
    {
        $this->assertSame('2.321.0', \RunnerDeck\Pool\Provisioner::versionFromDownload([
            'filename' => 'actions-runner-linux-x64-2.321.0.tar.gz',
        ]));
        $this->assertNull(\RunnerDeck\Pool\Provisioner::versionFromDownload(['filename' => 'unknown.bin']));
    }

    public function testExtractFailureMessageKeepsTheFirstErrorAndNamesEnosys(): void
    {
        $stderr = "tar: ./externals/node20: Cannot mkdir: Function not implemented\n"
            . "tar: ./bin/System.dll: Cannot open: Function not implemented\n"
            . "tar: Exiting with failure status due to previous errors\n";
        $message = \RunnerDeck\Pool\Provisioner::extractFailureMessage($stderr);

        $this->assertStringContainsString('externals/node20', $message);
        $this->assertStringContainsString('3 errors', $message);
        $this->assertStringContainsString('ENOSYS', $message);
        $this->assertStringNotContainsString('System.dll', $message);
    }

    #[RunInSeparateProcess]
    public function testScriptsWithoutTheListenerAreNotInstalled(): void
    {
        file_put_contents($this->dir . '/config.sh', '#!/bin/sh');
        $curled = false;
        \RunnerDeck\System\Shell::fake(function (array $cmd) use (&$curled): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return ['code' => 0, 'stdout' => $this->downloadsJson(hash('sha256', 'pkg')), 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $curled = true;
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, 'pkg');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'tar') {
                $this->plantPackage($cmd);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'chmod') {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertTrue($curled);
        $this->assertTrue($result['ok']);
        $this->assertFileExists($this->dir . '/bin/Runner.Listener');
        $this->assertDirectoryDoesNotExist($this->dir . '/.runnerdeck-extract');
    }

    #[RunInSeparateProcess]
    public function testFailedExtractRemovesThePartialTreeAndSummarizes(): void
    {
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return ['code' => 0, 'stdout' => $this->downloadsJson(hash('sha256', 'pkg')), 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, 'pkg');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'tar') {
                $this->plantPackage($cmd);
                @unlink($cmd[array_search('-C', $cmd, true) + 1] . '/bin/Runner.Listener');
                return [
                    'code' => 1,
                    'stdout' => '',
                    'stderr' => "tar: ./externals/node20: Cannot mkdir: Function not implemented\n"
                        . "tar: ./bin/System.dll: Cannot open: Function not implemented\n",
                ];
            }
            if (($cmd[0] ?? '') === 'which') {
                return ['code' => 1, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('2 errors', $result['message']);
        $this->assertStringContainsString('ENOSYS', $result['message']);
        $this->assertFileDoesNotExist($this->dir . '/config.sh');
        $this->assertDirectoryDoesNotExist($this->dir . '/.runnerdeck-extract');
        $this->assertFileDoesNotExist($this->dir . '/actions-runner-linux-x64.tar.gz');
    }

    #[RunInSeparateProcess]
    public function testBsdtarExtractsWhenGnuTarFails(): void
    {
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return ['code' => 0, 'stdout' => $this->downloadsJson(hash('sha256', 'pkg')), 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, 'pkg');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'tar') {
                $stderr = 'tar: ./bin/System.dll: Cannot open: Function not implemented';
                return ['code' => 1, 'stdout' => '', 'stderr' => $stderr];
            }
            if (($cmd[0] ?? '') === 'which') {
                return ['code' => 0, 'stdout' => "/usr/bin/bsdtar\n", 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'bsdtar') {
                $this->plantPackage($cmd);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'chmod') {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFileExists($this->dir . '/bin/Runner.Listener');
    }

    #[RunInSeparateProcess]
    public function testPharExtractsWhenTarAndBsdtarFail(): void
    {
        $archive = $this->dir . '/real.tar';
        $built = new \PharData($archive);
        $built->addFromString('config.sh', "#!/bin/sh\n");
        $built->addFromString('run.sh', "#!/bin/sh\n");
        $built->addFromString('bin/Runner.Listener', 'listener');
        $gz = $built->compress(\Phar::GZ);
        $bytes = (string) file_get_contents($gz->getPath());
        unset($gz, $built);
        @unlink($archive);
        @unlink($archive . '.gz');

        \RunnerDeck\System\Shell::fake(function (array $cmd) use ($bytes): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return ['code' => 0, 'stdout' => $this->downloadsJson(hash('sha256', $bytes)), 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, $bytes);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'tar' || ($cmd[0] ?? '') === 'bsdtar') {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'tar: Cannot open: Function not implemented'];
            }
            if (($cmd[0] ?? '') === 'which') {
                return ['code' => 0, 'stdout' => "/usr/bin/bsdtar\n", 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'chmod') {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Pool\Provisioner::ensureInstalled($this->dir);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFileExists($this->dir . '/bin/Runner.Listener');
        $this->assertDirectoryDoesNotExist($this->dir . '/.runnerdeck-extract');
    }

    #[RunInSeparateProcess]
    public function testProbeExtractReportsEnosysAndRemovesTheProbe(): void
    {
        \RunnerDeck\System\Shell::fake(function (array $cmd): array {
            if (($cmd[0] ?? '') === 'tar' && in_array('-cf', $cmd, true)) {
                $out = $cmd[array_search('-cf', $cmd, true) + 1];
                file_put_contents($out, 'not-a-tar');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'tar') {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'tar: s/f: Cannot open: Function not implemented'];
            }
            if (($cmd[0] ?? '') === 'which') {
                return ['code' => 1, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = \RunnerDeck\Pool\Provisioner::probeExtract($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ENOSYS', $result['message']);
        $this->assertDirectoryDoesNotExist($this->dir . '/.runnerdeck-extract-probe');
    }
}
