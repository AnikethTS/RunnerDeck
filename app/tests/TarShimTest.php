<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Pool\TarShim;
use RunnerDeck\System\Platform;
use RunnerDeck\System\Shell;

final class TarShimTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-tarshim-' . uniqid();
        mkdir($this->dir . '/storage', 0700, true);
        mkdir($this->dir . '/pool', 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/storage/settings.json');
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        putenv('PATH=/usr/bin:/bin');
    }

    protected function tearDown(): void
    {
        Shell::fake(null);
        Platform::fakeWindows(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_POOL_DIR');
        putenv('PATH');
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' && rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testProbeSkipsTheCacheWhenStorageCannotBeCreated(): void
    {
        $locked = $this->dir . '/locked';
        mkdir($locked, 0555);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $locked . '/storage/settings.json');
        $this->fakeTarWorks();

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFileDoesNotExist($locked . '/storage/tar-shim.json');
    }

    #[RunInSeparateProcess]
    public function testPrepareOnWindowsLeavesTheRunnerAlone(): void
    {
        Platform::fakeWindows(true);

        $this->assertNull(TarShim::prepare($this->dir . '/pool'));
        $this->assertFileDoesNotExist($this->dir . '/storage/shims/tar');
    }

    #[RunInSeparateProcess]
    public function testNoticeIgnoresABrokenCacheWhenThePoolCannotBeProbed(): void
    {
        file_put_contents($this->dir . '/storage/tar-shim.json', '{"banner":1,"at":1}');
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/missing/pool');

        $this->assertSame('', TarShim::notice());
    }

    #[RunInSeparateProcess]
    public function testNoticeReprobesWhenTheCacheIsStale(): void
    {
        file_put_contents(
            $this->dir . '/storage/tar-shim.json',
            json_encode(['banner' => 'stale', 'at' => time() - 1000])
        );
        $this->fakeTarWorks();

        $this->assertSame('', TarShim::notice());
    }

    #[RunInSeparateProcess]
    public function testNoticeProbesTheParentOfAPoolThatDoesNotExistYet(): void
    {
        $parent = $this->dir . '/parent';
        mkdir($parent, 0700, true);
        putenv('RUNNERDECK_POOL_DIR=' . $parent . '/pool');
        $this->fakeTarWorks();

        $this->assertSame('', TarShim::notice());
        $this->assertDirectoryDoesNotExist($parent . '/.runnerdeck-extract-probe');
    }

    #[RunInSeparateProcess]
    public function testPrepareUsesTheEnvironmentPathWhenTheSlotHasNone(): void
    {
        $this->fakeEnosysThenBsdtar();

        $shim = TarShim::prepare($this->dir . '/pool');

        $this->assertSame($this->dir . '/storage/shims', $shim);
        $path = trim((string) file_get_contents($this->dir . '/pool/.path'));
        $this->assertSame($this->dir . '/storage/shims:/usr/bin:/bin', $path);
    }

    #[RunInSeparateProcess]
    public function testPrepareRestoresTheEnvironmentPathWhenTheFileOnlyListsTheShim(): void
    {
        $shim = $this->dir . '/storage/shims';
        file_put_contents($this->dir . '/pool/.path', $shim . "\n");
        $this->fakeTarWorks();

        $this->assertNull(TarShim::prepare($this->dir . '/pool'));
        $this->assertSame('/usr/bin:/bin', trim((string) file_get_contents($this->dir . '/pool/.path')));
    }

    #[RunInSeparateProcess]
    public function testPrepareLeavesAPathThatDoesNotListTheShim(): void
    {
        file_put_contents($this->dir . '/pool/.path', "/usr/bin\n");
        $this->fakeTarWorks();

        $this->assertNull(TarShim::prepare($this->dir . '/pool'));
        $this->assertSame('/usr/bin', trim((string) file_get_contents($this->dir . '/pool/.path')));
    }

    #[RunInSeparateProcess]
    public function testPrepareSkipsASlotThatHasNoPathFileWhenTarWorks(): void
    {
        $this->fakeTarWorks();

        $this->assertNull(TarShim::prepare($this->dir . '/pool'));
        $this->assertFileDoesNotExist($this->dir . '/pool/.path');
    }

    #[RunInSeparateProcess]
    public function testProbeReportsWhenThePoolDirectoryIsNotWritable(): void
    {
        chmod($this->dir . '/pool', 0555);

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cannot create', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testPartialArchiveIsDiscardedAndBsdtarCreatesTheProbe(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'partial');
                return ['code' => 1, 'stdout' => '', 'stderr' => 'short write'];
            }
            if ($bin === 'which') {
                return ['code' => 0, 'stdout' => "/usr/bin/bsdtar\n", 'stderr' => ''];
            }
            if (str_ends_with($bin, 'bsdtar') && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'archive');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if ($bin === 'tar') {
                $this->writePayload($cmd);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFalse($result['shim']);
    }

    #[RunInSeparateProcess]
    public function testFailedBsdtarArchiveFallsThroughToPhar(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'no tar'];
            }
            if ($bin === 'which') {
                return ['code' => 0, 'stdout' => "/usr/bin/bsdtar\n", 'stderr' => ''];
            }
            if (str_ends_with($bin, 'bsdtar') && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'partial');
                return ['code' => 1, 'stdout' => '', 'stderr' => 'short write'];
            }
            if ($bin === 'tar') {
                $this->writePayload($cmd);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFalse($result['shim']);
    }

    #[RunInSeparateProcess]
    public function testProbeFailsWhenNoExtractorCanCreateAnArchive(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                $probe = $cmd[array_search('-C', $cmd, true) + 1];
                @unlink($probe . '/payload.txt');
                return ['code' => 1, 'stdout' => '', 'stderr' => 'no tar'];
            }
            if ($bin === 'which') {
                return ['code' => 1, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not create a probe archive', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testProbeNamesBothExtractorsWhenTheyReturnEnosys(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'archive');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if ($bin === 'which') {
                return ['code' => 0, 'stdout' => "/usr/bin/bsdtar\n", 'stderr' => ''];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'Cannot open: Function not implemented'];
        });

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertFalse($result['ok']);
        $this->assertSame(TarShim::BOTH_MESSAGE, $result['message']);
    }

    #[RunInSeparateProcess]
    public function testProbeReportsABsdtarFailureThatIsNotEnosys(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'archive');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if ($bin === 'tar') {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'tar: Cannot open: Function not implemented'];
            }
            if ($bin === 'which') {
                return ['code' => 0, 'stdout' => "/usr/bin/bsdtar\n", 'stderr' => ''];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'bsdtar: refused'];
        });

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('bsdtar: refused', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testProbeReportsWhenTheExtractDirectoryCannotBeCreated(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'archive');
                chmod($cmd[array_search('-C', $cmd, true) + 1], 0555);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cannot create', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testInstallSkipsWhenTheShimPathIsNotADirectory(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'archive');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if ($bin === 'tar') {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'tar: Cannot open: Function not implemented'];
            }
            if ($bin === 'which') {
                return ['code' => 0, 'stdout' => "/usr/bin/bsdtar\n", 'stderr' => ''];
            }
            $this->writePayload($cmd);
            file_put_contents($this->dir . '/storage/shims', '');
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $result = TarShim::probe($this->dir . '/pool');

        $this->assertTrue($result['shim']);
        $this->assertSame('', (string) file_get_contents($this->dir . '/storage/shims'));
    }

    private function fakeTarWorks(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'archive');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if ($bin === 'tar') {
                $this->writePayload($cmd);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            self::fail('unexpected command: ' . implode(' ', $cmd));
        });
    }

    private function fakeEnosysThenBsdtar(): void
    {
        Shell::fake(function (array $cmd): array {
            $bin = (string) ($cmd[0] ?? '');
            if ($bin === 'tar' && in_array('-cf', $cmd, true)) {
                file_put_contents($cmd[array_search('-cf', $cmd, true) + 1], 'archive');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if ($bin === 'tar') {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'tar: Cannot open: Function not implemented'];
            }
            if ($bin === 'which') {
                return ['code' => 0, 'stdout' => "/usr/bin/bsdtar\n", 'stderr' => ''];
            }
            $this->writePayload($cmd);
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
    }

    /** @param list<string> $cmd */
    private function writePayload(array $cmd): void
    {
        $dest = $cmd[array_search('-C', $cmd, true) + 1];
        file_put_contents($dest . '/payload.txt', 'ok');
    }
}
