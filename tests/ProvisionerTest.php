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
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_LABEL');
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, mixed> */
    private function packageMeta(string $sha = 'abc'): array
    {
        return [
            'download_url' => 'https://example.test/runner.tar.gz',
            'filename' => 'actions-runner-linux-x64.tar.gz',
            'sha256_checksum' => $sha,
        ];
    }

    /** @return list<array<string, mixed>> every GitHub os/arch pair so pickDownload works on CI hosts */
    private function downloadsForAnyHost(string $sha = 'abc'): array
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

    public function testChecksumMatchesWhenExpectedIsEmpty(): void
    {
        $path = $this->dir . '/pkg.bin';
        file_put_contents($path, 'payload');
        $this->assertTrue(\RunnerDeck\Provisioner::checksumMatches($path, null));
        $this->assertTrue(\RunnerDeck\Provisioner::checksumMatches($path, ''));
    }

    public function testChecksumMatchesComparesSha256(): void
    {
        $path = $this->dir . '/pkg.bin';
        file_put_contents($path, 'payload');
        $sha = hash('sha256', 'payload');

        $this->assertTrue(\RunnerDeck\Provisioner::checksumMatches($path, $sha));
        $this->assertTrue(\RunnerDeck\Provisioner::checksumMatches($path, strtoupper($sha)));
        $this->assertFalse(\RunnerDeck\Provisioner::checksumMatches($path, str_repeat('0', 64)));
    }

    public function testIsZipArchive(): void
    {
        $this->assertTrue(\RunnerDeck\Provisioner::isZipArchive('actions-runner-win-x64.zip'));
        $this->assertFalse(\RunnerDeck\Provisioner::isZipArchive('actions-runner-linux-x64.tar.gz'));
    }

    public function testMatchDownloadPicksOsAndArch(): void
    {
        $downloads = [
            ['os' => 'osx', 'architecture' => 'arm64', 'filename' => 'osx.tar.gz'],
            $this->packageMeta() + ['os' => 'linux', 'architecture' => 'x64'],
        ];

        $picked = \RunnerDeck\Provisioner::matchDownload($downloads, 'Linux', 'x86_64');

        $this->assertSame('actions-runner-linux-x64.tar.gz', $picked['filename']);
    }

    public function testMatchDownloadRejectsUnknownOs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported OS');
        \RunnerDeck\Provisioner::matchDownload([], 'Solaris', 'x86_64');
    }

    public function testMatchDownloadRejectsUnknownArch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported architecture');
        \RunnerDeck\Provisioner::matchDownload([], 'Linux', 'riscv64');
    }

    public function testMatchDownloadRejectsMissingPackage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no official runner package found for linux/x64');
        \RunnerDeck\Provisioner::matchDownload(
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

        \RunnerDeck\Provisioner::deregister($this->dir);

        $this->assertFileDoesNotExist($this->dir . '/.runner');
        $this->assertFileDoesNotExist($this->dir . '/.credentials');
        $this->assertFileDoesNotExist($this->dir . '/.credentials_rsaparams');
        $this->assertFileExists($this->dir . '/run.sh');
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledSkipsWhenBinariesPresent(): void
    {
        file_put_contents($this->dir . '/config.sh', '#!/bin/sh');
        $called = false;
        \RunnerDeck\Shell::fake(function () use (&$called): array {
            $called = true;
            return ['code' => 1, 'stdout' => '', 'stderr' => 'should not run'];
        });

        $result = \RunnerDeck\Provisioner::ensureInstalled($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertSame('runner binaries already present', $result['message']);
        $this->assertFalse($called);
    }

    #[RunInSeparateProcess]
    public function testEnsureInstalledAbortsOnChecksumMismatch(): void
    {
        $payload = 'corrupt-archive';
        \RunnerDeck\Shell::fake(function (array $cmd) use ($payload): array {
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

        $result = \RunnerDeck\Provisioner::ensureInstalled($this->dir);

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
        \RunnerDeck\Shell::fake(function (array $cmd) use ($payload, $sha): array {
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
                file_put_contents($this->dir . '/config.sh', '#!/bin/sh');
                file_put_contents($this->dir . '/run.sh', '#!/bin/sh');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'chmod') {
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Provisioner::ensureInstalled($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('runner binaries installed', $result['message']);
        $this->assertFileExists($this->dir . '/config.sh');
        $this->assertFileDoesNotExist($this->dir . '/actions-runner-linux-x64.tar.gz');
    }

    #[RunInSeparateProcess]
    public function testEnsureConfiguredSkipsWhenAlreadyRegistered(): void
    {
        file_put_contents($this->dir . '/config.sh', '#!/bin/sh');
        file_put_contents($this->dir . '/.runner', '{}');

        $result = \RunnerDeck\Provisioner::ensureConfigured($this->dir, 'acme-1');

        $this->assertTrue($result['ok']);
        $this->assertSame('acme-1 already configured', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testEnsureConfiguredFailsWhenConfigShFails(): void
    {
        file_put_contents($this->dir . '/config.sh', '#!/bin/sh');
        \RunnerDeck\Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'registration-token')) {
                return ['code' => 0, 'stdout' => "ghs_test\n", 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === './config.sh') {
                return ['code' => 1, 'stdout' => 'nope', 'stderr' => 'config failed'];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Provisioner::ensureConfigured($this->dir, 'acme-1');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('config.sh failed for acme-1', $result['message']);
        $this->assertStringContainsString('config failed', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testEnsureConfiguredRunsConfigShWithTokenAndLabels(): void
    {
        file_put_contents($this->dir . '/config.sh', '#!/bin/sh');
        $configCmd = [];
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$configCmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'registration-token')) {
                return ['code' => 0, 'stdout' => "ghs_test\n", 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === './config.sh') {
                $configCmd = $cmd;
                file_put_contents($this->dir . '/.runner', '{"agentName":"acme-1"}');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Provisioner::ensureConfigured($this->dir, 'acme-1');

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
        \RunnerDeck\Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return ['code' => 0, 'stdout' => json_encode($this->downloadsForAnyHost()), 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'curl') {
                return ['code' => 22, 'stdout' => '', 'stderr' => '404'];
            }
            self::fail('unexpected command: ' . $joined);
        });

        $result = \RunnerDeck\Provisioner::ensureInstalled($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed to download runner package', $result['message']);
        $this->assertStringContainsString('404', $result['message']);
    }
}
