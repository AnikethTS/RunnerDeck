<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class RuntimeRequirementsTest extends TestCase
{
    public function testPhpIsAtLeast81(): void
    {
        $this->assertGreaterThanOrEqual(80100, PHP_VERSION_ID);
    }

    public function testPosixAndPcntlAreLoaded(): void
    {
        $this->assertTrue(extension_loaded('posix'), 'posix is required (Fedora/RHEL: php-process)');
        $this->assertTrue(extension_loaded('pcntl'), 'pcntl is required (Fedora/RHEL: php-process)');
    }

    public function testPosixKillProbesThisProcess(): void
    {
        $pid = posix_getpid();
        $this->assertGreaterThan(0, $pid);
        $this->assertTrue(posix_kill($pid, 0));
    }

    public function testPcntlExposesSigterm(): void
    {
        $this->assertTrue(defined('SIGTERM'));
        $this->assertSame(15, SIGTERM);
    }

    public function testPdoSqliteIsLoadedSoHistoryCanRecord(): void
    {
        $this->assertTrue(extension_loaded('pdo_sqlite'));
        $this->assertTrue(\RunnerDeck\Host\History::isAvailable());
    }
}
