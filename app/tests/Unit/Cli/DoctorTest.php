<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\Doctor;
use RunnerDeck\Host\HostPressure;
use RunnerDeck\System\Shell;

final class DoctorTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $env = [
        'RUNNERDECK_POOL_DIR',
        'RUNNERDECK_SETTINGS_FILE',
        'RUNNERDECK_ORG',
        'RUNNERDECK_REPO',
        'RUNNERDECK_SCOPE',
        'RUNNERDECK_BIND_HOST',
        'RUNNERDECK_TRUST_PROXY',
        'RUNNERDECK_AUTH_TOTP_SECRET',
        'RUNNERDECK_GITHUB_APP_ID',
        'RUNNERDECK_GITHUB_APP_INSTALLATION_ID',
        'RUNNERDECK_GITHUB_APP_KEY_FILE',
        'RUNNERDECK_IP_ALLOWLIST',
        'RUNNERDECK_REQUIRE_MTLS',
    ];

    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-doctor-' . uniqid();
        mkdir($this->dir . '/pool', 0755, true);
        foreach ($this->env as $name) {
            $value = getenv($name);
            $this->saved[$name] = $value === false ? null : $value;
            putenv($name);
        }
        putenv('RUNNERDECK_POOL_DIR=' . $this->dir . '/pool');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        Shell::fake(static fn (): array => ['code' => 0, 'stdout' => '', 'stderr' => '']);
        HostPressure::fakeDisk(static fn (): array => [20 * 1024 * 1024 * 1024, 40 * 1024 * 1024 * 1024]);
        HostPressure::fakeRam(static fn (): int => 8 * 1024 * 1024);
    }

    protected function tearDown(): void
    {
        Shell::fake(null);
        HostPressure::fakeDisk(null);
        HostPressure::fakeRam(null);
        foreach ($this->saved as $name => $value) {
            if ($value === null) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testJsonListsTheSameChecksAsTheTextReport(): void
    {
        [$textCode, $text] = $this->doctor(['doctor']);
        [$jsonCode, $json] = $this->doctor(['doctor', '--json', '--quiet']);
        $data = json_decode($json, true);

        $this->assertSame($textCode, $jsonCode);
        $this->assertIsArray($data);
        $this->assertSame($textCode === 0, $data['ok']);
        $this->assertStringStartsWith('{', $json);
        $this->assertStringNotContainsString('RunnerDeck doctor', $json);
        $this->assertStringNotContainsString('[ OK ]', $json);

        $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
        preg_match_all('/\[( OK |WARN|FAIL)\] (.+)$/m', $plain, $rows, PREG_SET_ORDER);
        $this->assertNotEmpty($rows);
        $this->assertCount(count($rows), $data['checks']);

        foreach ($rows as $i => $row) {
            $parts = explode(' — ', $row[2], 2);
            $check = $data['checks'][$i];
            $this->assertSame(trim($parts[0]), $check['name']);
            $this->assertSame($parts[1] ?? '', $check['detail']);
            $this->assertIsBool($check['ok']);
            if ($row[1] === 'FAIL') {
                $this->assertFalse($check['ok']);
            } else {
                $this->assertTrue($check['ok']);
            }
        }
    }

    public function testAnUnconfiguredScopeIsAWarning(): void
    {
        [, $json] = $this->doctor(['doctor', '--json']);
        $data = json_decode($json, true);
        $scope = null;
        foreach ($data['checks'] as $check) {
            if ($check['name'] === 'scope') {
                $scope = $check;
            }
        }
        $this->assertNotNull($scope);
        $this->assertTrue($scope['ok']);
        $this->assertStringContainsString('not configured', $scope['detail']);
    }

    public function testAnUnwritablePoolIsAFailure(): void
    {
        putenv('RUNNERDECK_POOL_DIR=/no/such/runnerdeck-doctor-pool/runners');
        [$code, $json] = $this->doctor(['doctor', '--json']);
        $data = json_decode($json, true);
        $this->assertSame(1, $code);
        $this->assertFalse($data['ok']);
        $pool = null;
        foreach ($data['checks'] as $check) {
            if ($check['name'] === 'pool directory' && $check['ok'] === false) {
                $pool = $check;
            }
        }
        $this->assertNotNull($pool);
        $this->assertStringContainsString('is not writable', $pool['detail']);
    }

    /** @param list<string> $argv @return array{0: int, 1: string} */
    private function doctor(array $argv): array
    {
        $out = fopen('php://memory', 'w+');
        $this->assertIsResource($out);
        $code = Doctor::fromArgv($argv, $out)->run();
        rewind($out);
        return [$code, (string) stream_get_contents($out)];
    }
}
