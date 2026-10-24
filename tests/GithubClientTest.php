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
        \RunnerDeck\Core\Shell::fake(null);
        \RunnerDeck\GitHub\GithubApp::fakeToken(null);
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_SCOPE');
        putenv('GH_TOKEN');
        putenv('GITHUB_TOKEN');
    }

    public function testDecodeRunnerListMergesPaginatedNdjson(): void
    {
        $page1 = json_encode([
            'id' => 1,
            'name' => 'acme-1',
            'status' => 'online',
            'busy' => false,
            'labels' => [['name' => 'self-hosted']],
            'os' => 'Linux',
        ]);
        $page2 = json_encode([
            'id' => 2,
            'name' => 'acme-2',
            'status' => 'offline',
            'busy' => true,
            'labels' => [['name' => 'linux']],
        ]);

        $runners = \RunnerDeck\GitHub\GithubClient::decodeRunnerList($page1 . "\n" . $page2);

        $this->assertSame(['acme-1', 'acme-2'], array_keys($runners));
        $this->assertSame(1, $runners['acme-1']['id']);
        $this->assertTrue($runners['acme-2']['busy']);
        $this->assertSame(['linux'], $runners['acme-2']['labels']);
        $this->assertSame('Linux', $runners['acme-1']['os']);
        $this->assertSame('', $runners['acme-2']['os']);
    }

    public function testDecodeRunnerListAcceptsSinglePageObject(): void
    {
        $stdout = json_encode([
            'total_count' => 1,
            'runners' => [
                ['id' => 9, 'name' => 'acme-9', 'status' => 'online', 'busy' => false, 'labels' => []],
            ],
        ]);

        $runners = \RunnerDeck\GitHub\GithubClient::decodeRunnerList($stdout);

        $this->assertSame(9, $runners['acme-9']['id']);
    }

    public function testDecodeRunnerListEmptyStdoutIsEmptyMap(): void
    {
        $this->assertSame([], \RunnerDeck\GitHub\GithubClient::decodeRunnerList("  \n"));
    }

    #[RunInSeparateProcess]
    public function testListRunnersPassesPaginateAndPerPage(): void
    {
        $seen = null;
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$seen): array {
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

        $runners = \RunnerDeck\GitHub\GithubClient::listRunners();

        $this->assertContains('--paginate', $seen);
        $this->assertContains('-q', $seen);
        $this->assertContains('.runners[]', $seen);
        $this->assertTrue(
            is_string($seen[array_search('--paginate', $seen, true) + 1] ?? null)
            && str_contains($seen[array_search('--paginate', $seen, true) + 1], 'per_page=100')
        );
        $this->assertSame(1, $runners['acme-1']['id']);
    }

    #[RunInSeparateProcess]
    public function testGetJsonDecodesGhApiStdout(): void
    {
        $seen = null;
        \RunnerDeck\Core\Shell::fake(function (array $cmd) use (&$seen): array {
            $seen = $cmd;
            return ['code' => 0, 'stdout' => '{"ok":true}', 'stderr' => ''];
        });

        $decoded = \RunnerDeck\GitHub\GithubClient::getJson('repos/acme/app/actions/runs');

        $this->assertSame(['ok' => true], $decoded);
        $this->assertContains('api', $seen);
        $this->assertContains('repos/acme/app/actions/runs', $seen);
    }
}
