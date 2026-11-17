<?php

declare(strict_types=1);

namespace Tests\Support;

use RunnerDeck\Http\Api;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\System\Shell;

/**
 * Drives the HTTP action classes without a web server: an isolated pool and
 * settings folder, a fake `gh`, and the respond seam turned into a return value.
 */
trait HttpActionHarness
{
    private string $httpRoot = '';

    /** @var null|callable(array<int, string>): array{code: int, stdout: string, stderr: string} */
    private $ghHandler = null;

    protected function setUpHttp(): void
    {
        $this->httpRoot = sys_get_temp_dir() . '/runnerdeck-http-' . uniqid();
        mkdir($this->httpRoot . '/pool', 0755, true);
        putenv('RUNNERDECK_POOL_DIR=' . $this->httpRoot . '/pool');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->httpRoot . '/storage/settings.json');
        putenv('RUNNERDECK_HISTORY_FILE=' . $this->httpRoot . '/storage/db/history.sqlite');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_SYSLOG=0');
        RunnerPool::fakeLiveListeners([]);
        RunnerPool::fakeCheckProcess(fn () => [false, null]);
        \RunnerDeck\GitHub\AgentTarget::fake(fn () => null);
        $this->fakeGh(fn (): array => ['code' => 1, 'stdout' => '', 'stderr' => 'gh is down']);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDownHttp(): void
    {
        Api::fakeRespond(null);
        Shell::fake(null);
        RunnerPool::fakeLiveListeners(null);
        RunnerPool::fakeCheckProcess(null);
        \RunnerDeck\GitHub\AgentTarget::fake(null);
        $_POST = [];
        $_GET = [];
        foreach (['POOL_DIR', 'SETTINGS_FILE', 'HISTORY_FILE', 'ORG', 'SCOPE', 'SYSLOG'] as $name) {
            putenv('RUNNERDECK_' . $name);
        }
        if ($this->httpRoot !== '') {
            exec('rm -rf ' . escapeshellarg($this->httpRoot));
        }
    }

    /** @param callable(array<int, string>): array{code: int, stdout: string, stderr: string} $handler */
    protected function fakeGh(callable $handler): void
    {
        $this->ghHandler = $handler;
        Shell::fake($handler);
    }

    /** Make `gh api` report these runners: [['id' => 1, 'name' => 'acme-1', 'busy' => true], ...]. */
    protected function ghRunners(array $runners): void
    {
        $rows = array_map(
            static fn (array $r): array => $r + ['status' => 'online', 'busy' => false, 'labels' => []],
            $runners
        );
        $stdout = (string) json_encode(['runners' => $rows]);
        $this->fakeGh(fn (): array => ['code' => 0, 'stdout' => $stdout, 'stderr' => '']);
    }

    protected function slot(string $id, bool $configured = true): string
    {
        $dir = $this->httpRoot . '/pool/' . $id;
        mkdir($dir, 0755, true);
        if ($configured) {
            file_put_contents($dir . '/.runner', json_encode(['agentName' => 'acme-' . substr($id, 7)]));
        }
        return $dir;
    }

    /**
     * Run an action and return what it responded with.
     *
     * @param class-string $class
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     * @return array{status: int, data: array<string, mixed>}
     */
    protected function call(string $class, array $post = [], array $get = []): array
    {
        $_POST = $post;
        $_GET = $get;
        Api::fakeRespond(static function (array $data, int $status): never {
            throw new HttpResponse((string) json_encode(['status' => $status, 'data' => $data]));
        });
        ob_start();
        try {
            $class::handle();
        } catch (HttpResponse $r) {
            /** @var array{status: int, data: array<string, mixed>} $payload */
            $payload = json_decode($r->getMessage(), true);
            return $payload;
        } finally {
            ob_end_clean();
        }
        throw new \LogicException($class . ' returned without responding');
    }
}
