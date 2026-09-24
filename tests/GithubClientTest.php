<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GithubClientTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_SCOPE=org');
    }

    protected function tearDown(): void
    {
        \RunnerDeck\Shell::fake(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
    }

    public function testDecodeRunnerListMergesPaginatedNdjson(): void
    {
        $page1 = json_encode([
            'id' => 1,
            'name' => 'acme-1',
            'status' => 'online',
            'busy' => false,
            'labels' => [['name' => 'self-hosted']],
        ]);
        $page2 = json_encode([
            'id' => 2,
            'name' => 'acme-2',
            'status' => 'offline',
            'busy' => true,
            'labels' => [['name' => 'linux']],
        ]);

        $runners = \RunnerDeck\GithubClient::decodeRunnerList($page1 . "\n" . $page2);

        $this->assertSame(['acme-1', 'acme-2'], array_keys($runners));
        $this->assertSame(1, $runners['acme-1']['id']);
        $this->assertTrue($runners['acme-2']['busy']);
        $this->assertSame(['linux'], $runners['acme-2']['labels']);
    }

    public function testDecodeRunnerListAcceptsSinglePageObject(): void
    {
        $stdout = json_encode([
            'total_count' => 1,
            'runners' => [
                ['id' => 9, 'name' => 'acme-9', 'status' => 'online', 'busy' => false, 'labels' => []],
            ],
        ]);

        $runners = \RunnerDeck\GithubClient::decodeRunnerList($stdout);

        $this->assertSame(9, $runners['acme-9']['id']);
    }

    public function testDecodeRunnerListEmptyStdoutIsEmptyMap(): void
    {
        $this->assertSame([], \RunnerDeck\GithubClient::decodeRunnerList("  \n"));
    }

    #[RunInSeparateProcess]
    public function testListRunnersPassesPaginateAndPerPage(): void
    {
        $seen = null;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$seen): array {
            $seen = $cmd;
            return [
                'code' => 0,
                'stdout' => json_encode([
                    'id' => 1,
                    'name' => 'acme-1',
                    'status' => 'online',
                    'busy' => false,
                    'labels' => [],
                ]),
                'stderr' => '',
            ];
        });

        $runners = \RunnerDeck\GithubClient::listRunners();

        $this->assertContains('--paginate', $seen);
        $this->assertContains('-q', $seen);
        $this->assertContains('.runners[]', $seen);
        $this->assertTrue(
            is_string($seen[array_search('--paginate', $seen, true) + 1] ?? null)
            && str_contains($seen[array_search('--paginate', $seen, true) + 1], 'per_page=100')
        );
        $this->assertSame(1, $runners['acme-1']['id']);
    }
}
