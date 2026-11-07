<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Pool\RunnerInfo;

final class RollingUpgradeTest extends TestCase
{
    public function testSkipsUnconfiguredSlots(): void
    {
        $r = new RunnerInfo(
            id: 'runner-1',
            dir: '/tmp/missing-slot',
            configured: false,
            agentName: 'acme-1',
            localRunning: false,
            pid: null,
            logTail: [],
        );

        $result = \RunnerDeck\Lifecycle\RollingUpgrade::run([$r], true, 10);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('No configured runners', $result['message']);
    }

    public function testActionSizesTheTimeLimitFromConfiguredSlots(): void
    {
        $pool = sys_get_temp_dir() . '/runnerdeck-upgrade-action-' . uniqid();
        mkdir($pool . '/runner-1', 0777, true);
        file_put_contents($pool . '/runner-1/.runner', '{"agentName":"slot-1"}');
        mkdir($pool . '/runner-2');
        $previousOrg = getenv('RUNNERDECK_ORG');
        $previousScope = getenv('RUNNERDECK_SCOPE');
        $previousPool = getenv('RUNNERDECK_POOL_DIR');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_POOL_DIR=' . $pool);
        $_POST = ['timeout' => '30', 'force' => '1'];
        \RunnerDeck\Pool\RunnerPool::fakeLiveListeners([]);
        \RunnerDeck\Http\Api::fakeRespond(static function (array $data, int $status): never {
            throw new \RuntimeException((string) json_encode(['status' => $status, 'data' => $data]));
        });

        try {
            $this->expectOutputRegex('/RUNNERDECK_ORG is not set/');
            \RunnerDeck\Http\Action\Runners\RollingUpgradeAction::handle();
            $this->fail('the action returns through the response seam');
        } catch (\RuntimeException $e) {
            $payload = json_decode($e->getMessage(), true);
            $this->assertIsArray($payload);
            $this->assertSame(200, $payload['status']);
            $this->assertFalse($payload['data']['ok']);
            $this->assertStringContainsString('RUNNERDECK_ORG is not set', $payload['data']['message']);
            $this->assertSame(270, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit(0);
            $_POST = [];
            \RunnerDeck\Http\Api::fakeRespond(null);
            \RunnerDeck\Pool\RunnerPool::fakeLiveListeners(null);
            \RunnerDeck\Pool\RunnerPool::resetListenersCache();
            if ($previousOrg === false) {
                putenv('RUNNERDECK_ORG');
            } else {
                putenv('RUNNERDECK_ORG=' . $previousOrg);
            }
            if ($previousScope === false) {
                putenv('RUNNERDECK_SCOPE');
            } else {
                putenv('RUNNERDECK_SCOPE=' . $previousScope);
            }
            if ($previousPool === false) {
                putenv('RUNNERDECK_POOL_DIR');
            } else {
                putenv('RUNNERDECK_POOL_DIR=' . $previousPool);
            }
            exec('rm -rf ' . escapeshellarg($pool));
        }
    }
}
