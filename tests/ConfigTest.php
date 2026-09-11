<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['RUNNERDECK_ORG', 'RUNNERDECK_LABEL', 'RUNNERDECK_POOL_DIR', 'RUNNERDECK_SCOPE'] as $key) {
            putenv($key);
        }
    }

    #[RunInSeparateProcess]
    public function testOrgThrowsWhenUnset(): void
    {
        $this->expectException(RuntimeException::class);
        \Config::org();
    }

    #[RunInSeparateProcess]
    public function testOrgReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_ORG=my-org');
        $this->assertSame('my-org', \Config::org());
    }

    #[RunInSeparateProcess]
    public function testLabelDefaultsWhenUnset(): void
    {
        $this->assertSame('self-hosted-runnerdeck', \Config::label());
    }

    #[RunInSeparateProcess]
    public function testLabelReturnsEnvValue(): void
    {
        putenv('RUNNERDECK_LABEL=custom-label');
        $this->assertSame('custom-label', \Config::label());
    }

    #[RunInSeparateProcess]
    public function testPoolDirStripsTrailingSlash(): void
    {
        putenv('RUNNERDECK_POOL_DIR=/tmp/runners/');
        $this->assertSame('/tmp/runners', \Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testPoolDirDefaultsNextToRepoCheckout(): void
    {
        $expected = dirname(__DIR__, 2) . '/runners';
        $this->assertSame($expected, \Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testScopeDefaultsToOrg(): void
    {
        $this->assertSame('org', \Config::scope());
    }

    #[RunInSeparateProcess]
    public function testScopeAcceptsUser(): void
    {
        putenv('RUNNERDECK_SCOPE=user');
        $this->assertSame('user', \Config::scope());
    }

    #[RunInSeparateProcess]
    public function testScopeRejectsInvalidValue(): void
    {
        putenv('RUNNERDECK_SCOPE=repo');
        $this->expectException(RuntimeException::class);
        \Config::scope();
    }
}
