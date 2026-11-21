<?php

declare(strict_types=1);

namespace Tests\Unit\Pool;

use PHPUnit\Framework\TestCase;

final class PoolLayoutTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/runnerdeck-layout-' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_POOL_DIR');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testParentPackageJsonIsTheCheckoutAboveThePool(): void
    {
        mkdir($this->root . '/repo/data/runners', 0755, true);
        file_put_contents($this->root . '/repo/package.json', '{"type":"module"}');
        $pool = $this->root . '/repo/data/runners';

        $this->assertSame(
            $this->root . '/repo/package.json',
            \RunnerDeck\Pool\PoolLayout::parentPackageJson($pool)
        );
        $message = \RunnerDeck\Pool\PoolLayout::packageJsonBlockMessage($pool);
        $this->assertIsString($message);
        $this->assertStringContainsString('hashFiles', $message);
    }

    public function testAPoolBesideTheCheckoutHasNoPackageJsonAboveIt(): void
    {
        mkdir($this->root . '/repo', 0755, true);
        mkdir($this->root . '/runners', 0755, true);
        file_put_contents($this->root . '/repo/package.json', '{"type":"module"}');

        $this->assertNull(\RunnerDeck\Pool\PoolLayout::parentPackageJson($this->root . '/runners'));
    }

    public function testShareExternalsHardlinksTheSecondSlot(): void
    {
        $pool = $this->root . '/runners';
        $base = $pool . '/runner-base';
        $slot = $pool . '/runner-1';
        $this->agent($base, 'shared-bytes');
        $this->agent($slot, 'private-copy');
        putenv('RUNNERDECK_POOL_DIR=' . $pool);

        file_put_contents($base . '/bin/Runner.Listener', 'shared-listener');
        file_put_contents($slot . '/bin/Runner.Listener', 'copied-listener');

        \RunnerDeck\Pool\PoolLayout::shareExternals($slot);

        $donor = stat($base . '/externals/node/bin/node');
        $linked = stat($slot . '/externals/node/bin/node');
        $this->assertIsArray($donor);
        $this->assertIsArray($linked);
        $this->assertSame($donor['ino'], $linked['ino']);
        $this->assertSame('shared-bytes', file_get_contents($slot . '/externals/node/bin/node'));
        $binDonor = stat($base . '/bin/Runner.Listener');
        $binLinked = stat($slot . '/bin/Runner.Listener');
        $this->assertIsArray($binDonor);
        $this->assertIsArray($binLinked);
        $this->assertSame($binDonor['ino'], $binLinked['ino']);
        $this->assertSame('shared-listener', file_get_contents($slot . '/bin/Runner.Listener'));
        $this->assertFalse(\RunnerDeck\Pool\PoolLayout::externalsDuplicated());
        $this->assertFalse(\RunnerDeck\Pool\PoolLayout::agentTreesDuplicated());
    }

    public function testWhitespaceInThePoolPathIsAFailure(): void
    {
        $spaced = '/Users/me/Library/Application Support/RunnerDeck/runners';
        $message = \RunnerDeck\Pool\PoolLayout::whitespaceMessage($spaced);
        $this->assertIsString($message);
        $this->assertStringContainsString('unquoted path', $message);
        $this->assertNull(\RunnerDeck\Pool\PoolLayout::whitespaceMessage('/home/me/.runnerdeck/runners'));
    }

    public function testSpaceSentenceCountsOneSharedTree(): void
    {
        $sentence = \RunnerDeck\Pool\PoolLayout::spaceSentence(2, 900 * 1024);
        $this->assertStringContainsString('2 slots need', $sentence);
        $this->assertStringContainsString('900 MB', $sentence);
        $this->assertStringContainsString('once bin and externals are shared', $sentence);
    }

    private function agent(string $dir, string $nodeBody): void
    {
        mkdir($dir . '/bin', 0755, true);
        mkdir($dir . '/externals/node/bin', 0755, true);
        file_put_contents($dir . '/bin/Runner.Listener', 'listener');
        file_put_contents($dir . '/externals/node/bin/node', $nodeBody);
    }
}
