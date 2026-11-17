<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Action;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Http\Action\Administration\SaveSettingsAction;
use Tests\Support\HttpActionHarness;

final class SettingsValidationTest extends TestCase
{
    use HttpActionHarness;

    protected function setUp(): void
    {
        $this->setUpHttp();
    }

    protected function tearDown(): void
    {
        $this->tearDownHttp();
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidSettings(): array
    {
        return [
            'scope' => [['scope' => 'team'], "scope must be 'org' or 'repo'"],
            'org missing' => [['scope' => 'org', 'org' => ''], 'org is required for org scope'],
            'repo format' => [['scope' => 'repo', 'repo' => 'solo'], "repo must be in 'owner/repo' format"],
            'webhook url' => [['crash_webhook_url' => 'ftp://x'], 'crash webhook url must start with http'],
            'webhook threshold' => [
                ['crash_webhook_threshold' => '0'], 'crash webhook threshold must be a whole number',
            ],
            'drain timeout' => [['drain_timeout' => '7200'], 'drain timeout must be a whole number between 1 and 3600'],
            'disk threshold' => [['disk_webhook_threshold' => 'abc'], 'disk webhook threshold must be a whole number'],
            'session idle' => [
                ['session_idle_minutes' => '5000'], 'session idle timeout must be a whole number between 1 and 1440',
            ],
            'pool cap' => [['pool_cap' => '31'], 'pool cap must be a whole number between 1 and 30'],
            'idle stop' => [['idle_stop_minutes' => '0'], 'idle stop must be a whole number between 1 and 1440'],
            'queue wait' => [['queue_wait_minutes' => '1441'], 'queue wait alert must be a whole number'],
            'long job' => [['long_job_minutes' => 'x'], 'long job alert must be a whole number'],
            'min disk' => [
                ['min_free_disk_gib' => '1001'], 'minimum free disk must be a whole number of GiB from 0 to 1000',
            ],
            'min ram' => [['min_free_ram_mib' => '1048577'], 'minimum free RAM must be a whole number of MiB'],
            'app id' => [['github_app_id' => '0'], 'GitHub App ID must be a whole number'],
            'app installation' => [
                ['github_app_installation_id' => 'abc'], 'GitHub App installation ID must be a whole number',
            ],
            'app key' => [
                ['github_app_private_key' => 'not a key'], 'GitHub App private key must be a PEM private key',
            ],
            'slot cpu' => [['slot_cpu_percent' => '101'], 'slot CPU cap must be a whole number between 1 and 100'],
            'slot memory' => [['slot_memory_max_mib' => '63'], 'slot memory cap must be a whole number of MiB from 64'],
            'log retention' => [
                ['log_retention_days' => '366'], 'log retention must be a whole number of days from 1 to 365',
            ],
            'agent sha' => [['agent_sha' => 'abc123'], 'agent SHA must be a 64-character hex SHA-256'],
            'ip allowlist' => [['ip_allowlist' => 'not-an-ip'], 'IP allowlist must be comma or newline separated'],
        ];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('invalidSettings')]
    public function testEachBadValueIsRejectedWithItsOwnMessage(array $override, string $message): void
    {
        $res = $this->call(SaveSettingsAction::class, $override + ['scope' => 'org', 'org' => 'acme']);

        $this->assertSame(422, $res['status']);
        $this->assertFalse($res['data']['ok']);
        $this->assertStringContainsString($message, $res['data']['message']);
        $this->assertFileDoesNotExist($this->httpRoot . '/storage/settings.json');
    }

    public function testValidSettingsAreSaved(): void
    {
        $res = $this->call(SaveSettingsAction::class, [
            'scope' => 'org', 'org' => 'acme', 'label' => 'ci', 'pool_cap' => '5',
            'drain_timeout' => '300', 'min_free_disk_gib' => '0', 'min_free_ram_mib' => '0',
            'slot_cpu_percent' => '50', 'slot_memory_max_mib' => '2048', 'log_retention_days' => '14',
            'agent_sha' => str_repeat('A', 64), 'ip_allowlist' => '10.0.0.0/8, 192.168.1.5',
            'github_app_id' => '123', 'github_app_installation_id' => '456',
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $saved = (string) file_get_contents($this->httpRoot . '/storage/settings.json');
        $this->assertStringContainsString('"RUNNERDECK_POOL_CAP": "5"', $saved);
        $this->assertStringContainsString(str_repeat('a', 64), $saved);
        $this->assertStringContainsString('10.0.0.0/8,192.168.1.5', $saved);
    }

    public function testAPrivateKeyIsStoredOutsideTheSettingsFile(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----";

        $res = $this->call(SaveSettingsAction::class, [
            'scope' => 'org', 'org' => 'acme', 'github_app_private_key' => $pem,
        ]);

        $this->assertSame(200, $res['status']);
        $saved = (string) file_get_contents($this->httpRoot . '/storage/settings.json');
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $saved);
    }

    public function testAPrivateKeyThatCannotBeSavedIsReported(): void
    {
        file_put_contents($this->httpRoot . '/not-a-folder', 'x');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE=' . $this->httpRoot . '/not-a-folder/key.pem');
        $pem = "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----";

        try {
            $res = $this->call(SaveSettingsAction::class, [
                'scope' => 'org', 'org' => 'acme', 'github_app_private_key' => $pem,
            ]);
        } finally {
            putenv('RUNNERDECK_GITHUB_APP_KEY_FILE');
        }

        $this->assertSame(422, $res['status']);
        $this->assertSame('could not save GitHub App private key', $res['data']['message']);
    }
}
