<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Configuration\LauncherPaths;

final class LauncherPathsTest extends TestCase
{
    private string $config;

    /** @var string|false */
    private $xdg;

    /** @var string|false */
    private $home;

    protected function setUp(): void
    {
        $this->config = sys_get_temp_dir() . '/rd-launcher-' . uniqid();
        mkdir($this->config, 0700, true);
        $this->xdg = getenv('XDG_CONFIG_HOME');
        $this->home = getenv('HOME');
        putenv('XDG_CONFIG_HOME=' . $this->config);
    }

    protected function tearDown(): void
    {
        putenv($this->xdg === false ? 'XDG_CONFIG_HOME' : 'XDG_CONFIG_HOME=' . $this->xdg);
        putenv($this->home === false ? 'HOME' : 'HOME=' . $this->home);
        exec('rm -rf ' . escapeshellarg($this->config));
    }

    public function testARelativePathIsRejected(): void
    {
        $saved = LauncherPaths::savePoolDir('runners');

        $this->assertFalse($saved['ok']);
        $this->assertSame('pool-dir must be an absolute path', $saved['message']);
        $this->assertTrue(LauncherPaths::savePoolDir('C:\\runnerdeck\\pool')['ok']);
        $this->assertSame('C:\\runnerdeck\\pool', LauncherPaths::readPoolDir());
    }

    public function testCreateFailsWhenTheConfigParentIsAFile(): void
    {
        file_put_contents($this->config . '/runnerdeck', 'not-a-directory');

        $saved = LauncherPaths::savePoolDir('/tmp/runnerdeck-pool');

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('failed to create', $saved['message']);
    }

    public function testWriteFailsWhenTheTempPathIsADirectory(): void
    {
        mkdir($this->config . '/runnerdeck', 0700, true);
        mkdir($this->config . '/runnerdeck/pool-dir.' . getmypid() . '.tmp', 0700, true);

        $saved = LauncherPaths::savePoolDir('/tmp/runnerdeck-pool');

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('failed to write', $saved['message']);
        $this->assertFileDoesNotExist($this->config . '/runnerdeck/pool-dir');
    }

    public function testRenameDropsTheTempFileWhenTheDestinationIsADirectory(): void
    {
        mkdir($this->config . '/runnerdeck/pool-dir', 0700, true);

        $saved = LauncherPaths::savePoolDir('/tmp/runnerdeck-pool');

        $this->assertFalse($saved['ok']);
        $this->assertStringContainsString('failed to write', $saved['message']);
        $this->assertSame([], glob($this->config . '/runnerdeck/pool-dir.*.tmp') ?: []);
        $this->assertDirectoryExists($this->config . '/runnerdeck/pool-dir');
    }

    public function testPoolFileUsesHomeWhenTheConfigDirIsUnset(): void
    {
        $home = $this->config . '/home';
        mkdir($home, 0700, true);
        putenv('HOME=' . $home);
        putenv('XDG_CONFIG_HOME');

        $saved = LauncherPaths::savePoolDir('/tmp/runnerdeck-home-pool');

        $this->assertTrue($saved['ok'], $saved['message']);
        $this->assertSame(
            "/tmp/runnerdeck-home-pool\n",
            file_get_contents($home . '/.config/runnerdeck/pool-dir')
        );
    }
}
