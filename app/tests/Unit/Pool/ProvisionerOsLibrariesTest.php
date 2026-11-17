<?php

declare(strict_types=1);

namespace Tests\Unit\Pool;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Pool\Provisioner;
use RunnerDeck\System\Shell;

final class ProvisionerOsLibrariesTest extends TestCase
{
    private string $dir;
    private string $script;

    /** @var list<list<string>> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-oslibs-' . uniqid();
        mkdir($this->dir . '/bin', 0755, true);
        $this->script = $this->dir . '/bin/installdependencies.sh';
        file_put_contents($this->script, "#!/bin/sh\n");
        $this->ran = [];
        if (PHP_OS_FAMILY !== 'Linux' || is_file('/etc/alpine-release')) {
            $this->markTestSkipped('the OS library step only runs on glibc Linux');
        }
    }

    protected function tearDown(): void
    {
        Shell::fake(null);
        Provisioner::fakeHost(null, null);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * @param array<string, int> $codes exit code by the first word of the command; missing = 0
     */
    private function host(?bool $root, ?bool $sudo, array $codes = [], string $stderr = ''): void
    {
        Provisioner::fakeHost($root, $sudo);
        Shell::fake(function (array $cmd) use ($codes, $stderr): array {
            $this->ran[] = $cmd;
            $key = implode(' ', array_slice($cmd, 0, 3));
            foreach ($codes as $prefix => $code) {
                if (str_starts_with($key, $prefix)) {
                    return ['code' => $code, 'stdout' => '', 'stderr' => $code === 0 ? '' : $stderr];
                }
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
    }

    private function addListener(): void
    {
        file_put_contents($this->dir . '/bin/Runner.Listener', '');
    }

    #[RunInSeparateProcess]
    public function testNothingRunsWhenTheRunnerAlreadyStarts(): void
    {
        $this->addListener();
        $this->host(false, true);

        $result = Provisioner::installOsDependencies($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertSame('runner OS libraries already present', $result['message']);
        $this->assertSame([[$this->dir . '/bin/Runner.Listener', '--version']], $this->ran);
    }

    #[RunInSeparateProcess]
    public function testTheScriptRunsAsRootWhenTheRunnerWillNotStart(): void
    {
        $this->addListener();
        $this->host(true, true, [$this->dir . '/bin/Runner.Listener' => 127]);

        $result = Provisioner::installOsDependencies($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertSame('runner OS libraries installed', $result['message']);
        $this->assertContains(['bash', $this->script], $this->ran);
        foreach ($this->ran as $cmd) {
            $this->assertNotSame('sudo', $cmd[0]);
        }
    }

    #[RunInSeparateProcess]
    public function testNonRootUsesPasswordlessSudo(): void
    {
        $this->host(false, true);

        $result = Provisioner::installOsDependencies($this->dir);

        $this->assertTrue($result['ok']);
        $this->assertContains(['sudo', '-n', 'true'], $this->ran);
        $this->assertContains(['sudo', '-n', 'bash', $this->script], $this->ran);
    }

    #[RunInSeparateProcess]
    public function testASudoPasswordPromptIsExplainedWithTheCommandToRun(): void
    {
        $this->host(false, true, ['sudo -n true' => 1], 'sudo: a password is required');

        $result = Provisioner::installOsDependencies($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sudo needs a password', $result['message']);
        $manual = "Run this yourself, then try again: sudo bash '" . $this->script . "'";
        $this->assertStringContainsString($manual, $result['message']);
        $this->assertStringNotContainsString('will not start', $result['message']);
        $this->assertNotContains(['sudo', '-n', 'bash', $this->script], $this->ran);
    }

    #[RunInSeparateProcess]
    public function testMissingSudoIsExplainedWithTheCommandToRunAsRoot(): void
    {
        $this->host(false, false);

        $result = Provisioner::installOsDependencies($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sudo is not installed', $result['message']);
        $manual = "Run this as root, then try again: bash '" . $this->script . "'";
        $this->assertStringContainsString($manual, $result['message']);
        foreach ($this->ran as $cmd) {
            $this->assertNotSame('bash', $cmd[0]);
        }
    }

    #[RunInSeparateProcess]
    public function testAScriptFailureAsANonRootUserNamesTheSudoCommand(): void
    {
        $this->host(false, true, ['sudo -n bash' => 1], 'E: Unable to locate package libicu');

        $result = Provisioner::installOsDependencies($this->dir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('E: Unable to locate package libicu', $result['message']);
        $manual = "You can run it yourself: sudo bash '" . $this->script . "'";
        $this->assertStringContainsString($manual, $result['message']);
    }

    #[RunInSeparateProcess]
    public function testStatusPassesWhenAnInstalledRunnerStarts(): void
    {
        mkdir($this->dir . '/runner-1/bin', 0755, true);
        file_put_contents($this->dir . '/runner-1/bin/Runner.Listener', '');
        $this->host(false, false);

        $status = Provisioner::osLibrariesStatus($this->dir);

        $this->assertTrue($status['ok']);
        $this->assertStringContainsString('installed runner starts', $status['detail']);
    }

    #[RunInSeparateProcess]
    public function testStatusPassesAsRootOrWithPasswordlessSudo(): void
    {
        $this->host(true, false);
        $this->assertStringContainsString('as root', Provisioner::osLibrariesStatus($this->dir)['detail']);

        $this->host(false, true);
        $status = Provisioner::osLibrariesStatus($this->dir);
        $this->assertTrue($status['ok']);
        $this->assertStringContainsString('passwordless sudo', $status['detail']);
    }

    #[RunInSeparateProcess]
    public function testStatusPassesWhenLibicuIsAlreadyInstalled(): void
    {
        Provisioner::fakeHost(false, true);
        Shell::fake(fn (array $cmd): array => $cmd[0] === 'sudo'
            ? ['code' => 1, 'stdout' => '', 'stderr' => 'password required']
            : ['code' => 0, 'stdout' => "3\n", 'stderr' => '']);

        $status = Provisioner::osLibrariesStatus($this->dir);

        $this->assertTrue($status['ok']);
        $this->assertStringContainsString('libicu is installed', $status['detail']);
    }

    #[RunInSeparateProcess]
    public function testStatusWarnsWhenNothingWouldLetTheFirstRunnerStart(): void
    {
        Provisioner::fakeHost(false, true);
        Shell::fake(fn (array $cmd): array => $cmd[0] === 'sudo'
            ? ['code' => 1, 'stdout' => '', 'stderr' => 'password required']
            : ['code' => 1, 'stdout' => "0\n", 'stderr' => '']);

        $status = Provisioner::osLibrariesStatus($this->dir);

        $this->assertFalse($status['ok']);
        $this->assertStringContainsString('asks for a password', $status['detail']);
        $this->assertStringContainsString('passwordless sudo', $status['detail']);
    }
}
