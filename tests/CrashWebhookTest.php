<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CrashWebhookTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/runnerdeck-webhook-test-' . uniqid();
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->tmpDir . '/settings.json');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL');
        putenv('RUNNERDECK_SETTINGS_FILE');
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    #[RunInSeparateProcess]
    public function testNoRequestWhenUrlIsUnset(): void
    {
        $called = false;
        \RunnerDeck\Shell::fake(function () use (&$called): array {
            $called = true;
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1');

        $this->assertFalse($called);
    }

    #[RunInSeparateProcess]
    public function testSlackUrlGetsTextPayload(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://hooks.slack.com/services/x');
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1');

        $decoded = json_decode((string) $body, true);
        $this->assertArrayHasKey('text', $decoded);
        $this->assertStringContainsString('acme-1', $decoded['text']);
    }

    #[RunInSeparateProcess]
    public function testDiscordUrlGetsContentPayload(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://discord.com/api/webhooks/x/y');
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1');

        $decoded = json_decode((string) $body, true);
        $this->assertArrayHasKey('content', $decoded);
    }

    #[RunInSeparateProcess]
    public function testGenericUrlGetsStructuredPayload(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1');

        $decoded = json_decode((string) $body, true);
        $this->assertSame('crash_loop', $decoded['event']);
        $this->assertSame('runner-1', $decoded['runner']);
        $this->assertSame('acme-1', $decoded['agent_name']);
    }

    #[RunInSeparateProcess]
    public function testDoesNotThrowWhenCurlFails(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        \RunnerDeck\Shell::fake(fn () => ['code' => 6, 'stdout' => '', 'stderr' => 'could not resolve host']);

        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1');

        $this->addToAssertionCount(1);
    }

    public function testIsValidUrlAcceptsHttpAndHttps(): void
    {
        $this->assertTrue(\RunnerDeck\CrashWebhook::isValidUrl('https://example.com/hook'));
        $this->assertTrue(\RunnerDeck\CrashWebhook::isValidUrl('http://example.com/hook'));
    }

    public function testIsValidUrlRejectsOtherSchemesAndGarbage(): void
    {
        $this->assertFalse(\RunnerDeck\CrashWebhook::isValidUrl('ftp://example.com'));
        $this->assertFalse(\RunnerDeck\CrashWebhook::isValidUrl('not-a-url'));
        $this->assertFalse(\RunnerDeck\CrashWebhook::isValidUrl(''));
    }

    #[RunInSeparateProcess]
    public function testSendTestUsesADistinctMessageAndDoesNotIncludeARunner(): void
    {
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $result = \RunnerDeck\CrashWebhook::sendTest('https://example.com/hook');

        $this->assertTrue($result['ok']);
        $decoded = json_decode((string) $body, true);
        $this->assertSame('test', $decoded['event']);
        $this->assertArrayNotHasKey('runner', $decoded);
        $this->assertStringContainsString('test notification', $decoded['message']);
    }

    #[RunInSeparateProcess]
    public function testSendTestReportsFailureWithStderr(): void
    {
        \RunnerDeck\Shell::fake(fn () => ['code' => 6, 'stdout' => '', 'stderr' => 'could not resolve host']);

        $result = \RunnerDeck\CrashWebhook::sendTest('https://example.com/hook');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not resolve host', $result['message']);
    }

    #[RunInSeparateProcess]
    public function testSendTestRespectsSlackPayloadShape(): void
    {
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::sendTest('https://hooks.slack.com/services/x');

        $decoded = json_decode((string) $body, true);
        $this->assertArrayHasKey('text', $decoded);
        $this->assertArrayNotHasKey('event', $decoded);
    }
}
