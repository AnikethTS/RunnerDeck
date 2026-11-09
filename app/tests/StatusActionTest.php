<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class StatusActionTest extends TestCase
{
    private string $root;
    private string $runnerDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runnerdeck-status-action-' . uniqid();
        $this->runnerDir = $this->root . '/pool/runner-1';
        mkdir($this->runnerDir, 0755, true);
        file_put_contents($this->runnerDir . '/.runner', json_encode([
            'agentName' => 'acme-1',
            'agentVersion' => '2.321.0',
        ]));
        file_put_contents($this->runnerDir . '/runner.log', "hello from the slot\n");

        putenv('RUNNERDECK_POOL_DIR=' . $this->root . '/pool');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->root . '/storage/settings.json');
        putenv('RUNNERDECK_HISTORY_FILE=' . $this->root . '/storage/db/history.sqlite');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        \RunnerDeck\GitHub\AgentTarget::fake(fn () => null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\GitHub\AgentTarget::fake(null);
        \RunnerDeck\System\Shell::fake(null);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners(null);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(null);
        \RunnerDeck\Http\Api::fakeRespond(null);
        $_GET = [];
        putenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_HISTORY_FILE');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[RunInSeparateProcess]
    public function testViewPollOmitsLogTailsAlreadyPaintedInTheRow(): void
    {
        \RunnerDeck\System\Shell::fake(fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in']);
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Pool\RunnerPool::fakeCheckProcess(fn () => [false, null]);
        $_GET = ['view' => '1', 'lines' => '5'];
        \RunnerDeck\Http\Api::fakeRespond(static function (array $data, int $status): never {
            throw new \RuntimeException((string) json_encode(['status' => $status, 'data' => $data]));
        });

        $payload = null;
        ob_start();
        try {
            \RunnerDeck\Http\Action\Monitoring\StatusAction::handle();
            $this->fail('the action returns through the response seam');
        } catch (\RuntimeException $e) {
            $payload = json_decode($e->getMessage(), true);
        } finally {
            ob_end_clean();
        }

        $this->assertIsArray($payload);
        $this->assertSame(200, $payload['status']);
        $this->assertArrayNotHasKey('log_tail', $payload['data']['runners'][0]);
        $this->assertSame('runner-1', $payload['data']['runners'][0]['id']);
        $this->assertStringContainsString('hello from the slot', $payload['data']['view']['rows_html']);
    }

    public function testWithoutLogTailsDropsNonRows(): void
    {
        $method = new ReflectionMethod(\RunnerDeck\Http\Action\Monitoring\StatusAction::class, 'withoutLogTails');
        $this->assertSame([], $method->invoke(null, null));
        $this->assertSame([], $method->invoke(null, 'nope'));

        $rows = $method->invoke(null, [
            'skip' => 'not-a-row',
            ['id' => 'runner-1', 'log_tail' => ['secret'], 'agent_name' => 'acme'],
        ]);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('log_tail', $rows[0]);
        $this->assertSame('acme', $rows[0]['agent_name']);
    }
}
