<?php

declare(strict_types=1);

namespace RunnerDeck\Tests\Unit\Pool;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Pool\Provisioner;
use RunnerDeck\System\Shell;

final class HostileArchiveTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->wipe($this->root);
        }
        parent::tearDown();
    }

    public function testAParentPathEntryDoesNotEscapeTheSlot(): void
    {
        $outside = $this->layout();
        $archive = $this->archive([
            ['name' => '../escape.txt', 'data' => 'nope'],
            ['name' => 'inside.txt', 'data' => 'ok'],
        ]);
        $this->extract($archive);
        $this->assertFileDoesNotExist($outside . '/escape.txt');
        $this->assertFileDoesNotExist(dirname($outside) . '/escape.txt');
        $this->assertNothingOutside($this->root . '/stage', $outside);
    }

    public function testAnAbsolutePathEntryDoesNotEscapeTheSlot(): void
    {
        $outside = $this->layout();
        $archive = $this->archive([
            ['name' => $outside . '/absolute.txt', 'data' => 'nope'],
        ]);
        $this->extract($archive);
        $this->assertFileDoesNotExist($outside . '/absolute.txt');
        $this->assertNothingOutside($this->root . '/stage', $outside);
    }

    public function testASymlinkPointingOutsideIsRefused(): void
    {
        $outside = $this->layout();
        $archive = $this->archive([
            ['name' => 'out', 'type' => '2', 'link' => $outside, 'data' => ''],
            ['name' => 'out/pwned.txt', 'data' => 'nope'],
        ]);
        $result = $this->extract($archive);
        $this->assertFileDoesNotExist($outside . '/pwned.txt');
        $this->assertNothingOutside($this->root . '/stage', $outside);
        if (is_dir($this->root . '/stage')) {
            $this->assertFalse(is_link($this->root . '/stage/out'));
        } else {
            $this->assertFalse($result['ok']);
        }
    }

    public function testATruncatedArchiveRemovesTheStage(): void
    {
        $this->layout();
        $archive = $this->root . '/truncated.tar.gz';
        file_put_contents($archive, 'this is not a gzip tarball');
        $result = $this->extract($archive);
        $this->assertFalse($result['ok']);
        $this->assertDirectoryDoesNotExist($this->root . '/stage');
    }

    #[RunInSeparateProcess]
    public function testAChecksumMismatchLeavesTheSlotUntouched(): void
    {
        $slot = sys_get_temp_dir() . '/rd-hostile-sum-' . bin2hex(random_bytes(4));
        mkdir($slot);
        file_put_contents($slot . '/keep-me.txt', 'kept');
        Shell::fake(function (array $cmd): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return ['code' => 0, 'stdout' => self::downloadsJson(hash('sha256', 'expected-bytes')), 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, 'corrupt-archive');
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'unexpected ' . $joined];
        });

        try {
            $result = Provisioner::ensureInstalled($slot);
            $this->assertFalse($result['ok']);
            $this->assertSame('kept', file_get_contents($slot . '/keep-me.txt'));
            $this->assertDirectoryDoesNotExist($slot . '/.runnerdeck-extract');
            $this->assertFileDoesNotExist($slot . '/run.sh');
        } finally {
            $this->wipe($slot);
        }
    }

    #[RunInSeparateProcess]
    public function testATruncatedDownloadDoesNotLeaveTheSlotHalfInstalled(): void
    {
        $slot = sys_get_temp_dir() . '/rd-hostile-trunc-' . bin2hex(random_bytes(4));
        mkdir($slot);
        file_put_contents($slot . '/keep-me.txt', 'kept');
        $body = 'not-a-tarball';
        Shell::fake(function (array $cmd) use ($body): array {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, '/actions/runners/downloads')) {
                return ['code' => 0, 'stdout' => self::downloadsJson(hash('sha256', $body)), 'stderr' => ''];
            }
            if (($cmd[0] ?? '') === 'curl') {
                $out = $cmd[array_search('-o', $cmd, true) + 1];
                file_put_contents($out, $body);
                return ['code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            if (in_array($cmd[0] ?? '', ['tar', 'bsdtar', 'unzip'], true)) {
                return ['code' => 2, 'stdout' => '', 'stderr' => 'truncated'];
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'unexpected ' . $joined];
        });

        try {
            $result = Provisioner::ensureInstalled($slot);
            $this->assertFalse($result['ok']);
            $this->assertSame('kept', file_get_contents($slot . '/keep-me.txt'));
            $this->assertDirectoryDoesNotExist($slot . '/.runnerdeck-extract');
            $this->assertFileDoesNotExist($slot . '/run.sh');
        } finally {
            $this->wipe($slot);
        }
    }

    private function layout(): string
    {
        if (getenv('PATH') === false || getenv('PATH') === '') {
            $this->markTestSkipped('tar is not on PATH');
        }
        $this->root = sys_get_temp_dir() . '/rd-hostile-' . bin2hex(random_bytes(4));
        $outside = $this->root . '/outside';
        mkdir($outside, 0700, true);
        return $outside;
    }

    /**
     * @param list<array{name: string, data: string, type?: string, link?: string}> $entries
     */
    private function archive(array $entries): string
    {
        $tar = '';
        foreach ($entries as $entry) {
            $data = $entry['data'];
            $tar .= $this->header(
                $entry['name'],
                $entry['type'] ?? '0',
                $entry['link'] ?? '',
                strlen($data)
            );
            $tar .= $data;
            $tar .= str_repeat("\0", (512 - (strlen($data) % 512)) % 512);
        }
        $tar .= str_repeat("\0", 1024);
        $path = $this->root . '/hostile.tar.gz';
        file_put_contents($path, gzencode($tar));
        return $path;
    }

    private function header(string $name, string $type, string $link, int $size): string
    {
        $header = str_repeat("\0", 512);
        $write = static function (string $header, int $at, string $value): string {
            return substr_replace($header, $value, $at, strlen($value));
        };
        $header = $write($header, 0, $name);
        $header = $write($header, 100, sprintf('%07o', 0644) . "\0");
        $header = $write($header, 108, sprintf('%07o', 0) . "\0");
        $header = $write($header, 116, sprintf('%07o', 0) . "\0");
        $header = $write($header, 124, sprintf('%011o', $size) . "\0");
        $header = $write($header, 136, sprintf('%011o', time()) . "\0");
        $header = $write($header, 148, '        ');
        $header = $write($header, 156, $type);
        $header = $write($header, 157, $link);
        $header = $write($header, 257, "ustar\0");
        $header = $write($header, 263, '00');
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }
        return $write($header, 148, sprintf('%06o', $sum) . "\0 ");
    }

    /** @return array{ok: bool, stderr: string} */
    private function extract(string $archive): array
    {
        $method = new \ReflectionMethod(Provisioner::class, 'extractWithFallback');
        $dest = $this->root . '/stage';
        /** @var array{ok: bool, stderr: string} $result */
        $result = $method->invoke(null, $archive, $dest, [
            ['tar', 'xzf', $archive, '-C', $dest],
            ['bsdtar', '-xzf', $archive, '-C', $dest],
        ]);
        return $result;
    }

    private function assertNothingOutside(string $stage, string $outside): void
    {
        $this->assertSame([], glob($outside . '/*') ?: []);
        if (!is_dir($stage)) {
            return;
        }
        $root = realpath($stage);
        $this->assertNotFalse($root);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->isLink()) {
                continue;
            }
            $real = realpath($item->getPathname());
            $this->assertNotFalse($real);
            $this->assertTrue($real === $root || str_starts_with($real, $root . '/'));
        }
    }

    private static function downloadsJson(string $sha): string
    {
        $out = [];
        foreach (['linux', 'osx', 'win'] as $os) {
            foreach (['x64', 'arm64', 'arm'] as $arch) {
                $out[] = [
                    'os' => $os,
                    'architecture' => $arch,
                    'download_url' => 'https://example.test/runner.tar.gz',
                    'filename' => 'actions-runner-linux-x64.tar.gz',
                    'sha256_checksum' => $sha,
                ];
            }
        }
        $encoded = json_encode($out);
        return is_string($encoded) ? $encoded : '[]';
    }

    private function wipe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
