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
        putenv('RUNNERDECK_SYSLOG');
        putenv('RUNNERDECK_SETTINGS_FILE');
        \RunnerDeck\SyslogSink::fakeSend(null);
        \RunnerDeck\WebhookOutbox::fakeNow(null);
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
    public function testThresholdNotifyUsesDistinctEventAndCount(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1', 'crash_threshold', 5, 5);

        $decoded = json_decode((string) $body, true);
        $this->assertSame('crash_threshold', $decoded['event']);
        $this->assertSame(5, $decoded['crash_count_7d']);
        $this->assertSame(5, $decoded['threshold']);
        $this->assertStringContainsString('5 crashes', $decoded['message']);
    }

    #[RunInSeparateProcess]
    public function testMismatchNotifyUsesDistinctEvent(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1', 'mismatch');

        $decoded = json_decode((string) $body, true);
        $this->assertSame('mismatch', $decoded['event']);
        $this->assertStringContainsString('disagree', $decoded['message']);
    }

    #[RunInSeparateProcess]
    public function testNotifyEventPostsStructuredPayloadWithoutRunner(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::notifyEvent('host_pressure', 'low disk');

        $decoded = json_decode((string) $body, true);
        $this->assertSame('host_pressure', $decoded['event']);
        $this->assertSame('low disk', $decoded['message']);
        $this->assertArrayNotHasKey('runner', $decoded);
    }

    #[RunInSeparateProcess]
    public function testDiskNotifyUsesDiskPayload(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        $body = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$body): array {
            $body = $cmd[array_search('-d', $cmd, true) + 1];
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        \RunnerDeck\CrashWebhook::notifyDisk('disk_pool', 'pool full', null, null, 2048, 1);

        $decoded = json_decode((string) $body, true);
        $this->assertSame('disk_pool', $decoded['event']);
        $this->assertSame(2048, $decoded['disk_kb']);
        $this->assertSame(1, $decoded['threshold_gb']);
    }

    #[RunInSeparateProcess]
    public function testDoesNotThrowWhenCurlFails(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        \RunnerDeck\Shell::fake(fn () => ['code' => 6, 'stdout' => '', 'stderr' => 'could not resolve host']);

        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1');

        $this->addToAssertionCount(1);
        $this->assertSame(1, \RunnerDeck\WebhookOutbox::pendingCount());
    }

    #[RunInSeparateProcess]
    public function testFlushOutboxRetriesUntilSuccessAndDoesNotDuplicate(): void
    {
        putenv('RUNNERDECK_CRASH_WEBHOOK_URL=https://example.com/hook');
        mkdir($this->tmpDir, 0700, true);
        $calls = 0;
        \RunnerDeck\Shell::fake(function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return ['code' => 6, 'stdout' => '', 'stderr' => 'down'];
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        \RunnerDeck\WebhookOutbox::fakeNow(1000);
        \RunnerDeck\CrashWebhook::notify('runner-1', 'acme-1');
        $this->assertSame(1, \RunnerDeck\WebhookOutbox::pendingCount());

        \RunnerDeck\WebhookOutbox::fakeNow(1100);
        \RunnerDeck\CrashWebhook::flushOutbox();
        $this->assertSame(0, \RunnerDeck\WebhookOutbox::pendingCount());
        $this->assertSame(2, $calls);

        \RunnerDeck\CrashWebhook::flushOutbox();
        $this->assertSame(2, $calls);
    }

    #[RunInSeparateProcess]
    public function testSyslogEmitsWithoutWebhookUrl(): void
    {
        $seen = [];
        \RunnerDeck\SyslogSink::fakeSend(function (string $line) use (&$seen): bool {
            $seen[] = $line;
            return true;
        });
        putenv('RUNNERDECK_SYSLOG=1');
        \RunnerDeck\CrashWebhook::notifyEvent('host_pressure', 'low disk');
        $this->assertCount(1, $seen);
        $this->assertStringContainsString('host_pressure', $seen[0]);
        $this->assertStringContainsString('low disk', $seen[0]);
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
