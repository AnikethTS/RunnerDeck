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
        foreach (['RUNNER_DASHBOARD_ORG', 'RUNNER_DASHBOARD_LABEL', 'RUNNER_DASHBOARD_POOL_DIR'] as $key) {
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
        putenv('RUNNER_DASHBOARD_ORG=my-org');
        $this->assertSame('my-org', \Config::org());
    }

    #[RunInSeparateProcess]
    public function testLabelDefaultsWhenUnset(): void
    {
        $this->assertSame('self-hosted-dashboard', \Config::label());
    }

    #[RunInSeparateProcess]
    public function testLabelReturnsEnvValue(): void
    {
        putenv('RUNNER_DASHBOARD_LABEL=custom-label');
        $this->assertSame('custom-label', \Config::label());
    }

    #[RunInSeparateProcess]
    public function testPoolDirStripsTrailingSlash(): void
    {
        putenv('RUNNER_DASHBOARD_POOL_DIR=/tmp/runners/');
        $this->assertSame('/tmp/runners', \Config::poolDir());
    }

    #[RunInSeparateProcess]
    public function testPoolDirDefaultsNextToRepoCheckout(): void
    {
        $expected = dirname(__DIR__, 2) . '/runners';
        $this->assertSame($expected, \Config::poolDir());
    }
}
