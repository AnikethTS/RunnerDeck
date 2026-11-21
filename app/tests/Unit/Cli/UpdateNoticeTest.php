<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RunnerDeck\Cli\UpdateNotice;
use RunnerDeck\Configuration\Config;
use RunnerDeck\Configuration\Settings;
use RunnerDeck\System\Shell;

final class UpdateNoticeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-update-notice-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_NO_UPDATE_NOTICE');
    }

    protected function tearDown(): void
    {
        UpdateNotice::fakeChecker(null);
        Shell::fake(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_NO_UPDATE_NOTICE');
    }

    public function testAFreshCacheNamesTheReleaseWithoutAskingGitHub(): void
    {
        $this->writeCache(time(), '99.0.0');
        $calls = 0;
        Shell::fake(function () use (&$calls): array {
            $calls++;
            return ['code' => 1, 'stdout' => '', 'stderr' => 'should not run'];
        });

        $line = UpdateNotice::line(true);

        $this->assertSame(0, $calls);
        $this->assertNotNull($line);
        $this->assertStringContainsString(
            '↑ RunnerDeck 99.0.0 is available (you have ' . Config::version() . ')',
            (string) $line
        );
        $this->assertStringContainsString('Run: runnerdeck update', (string) $line);
        $this->assertStringContainsString(
            'https://github.com/AnikethTS/RunnerDeck/releases/tag/v99.0.0',
            (string) $line
        );
    }

    public function testTheSameVersionSaysNothing(): void
    {
        $this->writeCache(time(), Config::version());
        $this->assertNull(UpdateNotice::line(true));
    }

    public function testAStaleCacheAsksOnceAndThenStaysQuiet(): void
    {
        $this->writeCache(time() - 90000, '');
        $calls = 0;
        Shell::fake(function () use (&$calls): array {
            $calls++;
            return ['code' => 0, 'stdout' => '{"tag_name":"v99.1.0"}', 'stderr' => ''];
        });

        $first = UpdateNotice::line(true);
        $second = UpdateNotice::line(true);

        $this->assertSame(1, $calls);
        $this->assertStringContainsString('99.1.0', (string) $first);
        $this->assertSame($first, $second);
        $saved = json_decode((string) file_get_contents(Settings::storageDir() . '/cli-update.json'), true);
        $this->assertSame('99.1.0', $saved['latest']);
    }

    public function testAFailedCheckStaysQuietAndIsNotRetriedTheSameDay(): void
    {
        $calls = 0;
        Shell::fake(function () use (&$calls): array {
            $calls++;
            return ['code' => 1, 'stdout' => '', 'stderr' => 'offline'];
        });

        $this->assertNull(UpdateNotice::line(true));
        $this->assertNull(UpdateNotice::line(true));
        $this->assertSame(1, $calls);
    }

    public function testSkippingTheRefreshLeavesAStaleAnswerInPlace(): void
    {
        $this->writeCache(time() - 90000, '98.0.0');
        Shell::fake(fn (): array => ['code' => 0, 'stdout' => '{"tag_name":"v99.0.0"}', 'stderr' => '']);

        $line = UpdateNotice::line(false);

        $this->assertStringContainsString('98.0.0', (string) $line);
        $this->assertStringNotContainsString('99.0.0', (string) $line);
    }

    public function testTheNoticeCanBeTurnedOff(): void
    {
        $this->writeCache(time(), '99.0.0');
        putenv('RUNNERDECK_NO_UPDATE_NOTICE=1');
        $this->assertNull(UpdateNotice::line(true));
    }

    public function testABrokenCacheFileIsIgnored(): void
    {
        file_put_contents(Settings::storageDir() . '/cli-update.json', 'not-json');
        $this->assertNull(UpdateNotice::line(false));

        file_put_contents(
            Settings::storageDir() . '/cli-update.json',
            json_encode(['checked_at' => time(), 'latest' => 12])
        );
        $this->assertNull(UpdateNotice::line(false));
    }

    public function testAnUnusableReleaseNameIsNotAnnounced(): void
    {
        UpdateNotice::fakeChecker(fn (): array => [
            'current' => Config::version(),
            'latest' => 'not a version',
            'update_available' => false,
            'error' => null,
        ]);

        $this->assertNull(UpdateNotice::line(true));
        $saved = json_decode((string) file_get_contents(Settings::storageDir() . '/cli-update.json'), true);
        $this->assertSame('', $saved['latest']);
    }

    public function testAMissingLatestFromTheCheckerStaysQuiet(): void
    {
        UpdateNotice::fakeChecker(fn (): array => [
            'current' => Config::version(),
            'latest' => null,
            'update_available' => false,
            'error' => null,
        ]);

        $this->assertNull(UpdateNotice::line(true));
    }

    public function testTheAnswerIsStillShownWhenTheCacheCannotBeSaved(): void
    {
        $blocker = $this->dir . '/not-a-directory';
        file_put_contents($blocker, 'x');
        putenv('RUNNERDECK_SETTINGS_FILE=' . $blocker . '/settings.json');
        UpdateNotice::fakeChecker(fn (): array => [
            'current' => Config::version(),
            'latest' => '99.4.0',
            'update_available' => true,
            'error' => null,
        ]);

        $line = UpdateNotice::line(true);

        $this->assertStringContainsString('99.4.0', (string) $line);
        $this->assertFileDoesNotExist($blocker . '/cli-update.json');
    }

    public function testABlockedTempFileDoesNotHideTheAnswer(): void
    {
        mkdir(Settings::storageDir() . '/cli-update.json.' . getmypid() . '.tmp');
        UpdateNotice::fakeChecker(fn (): array => [
            'current' => Config::version(),
            'latest' => '99.5.0',
            'update_available' => true,
            'error' => null,
        ]);

        $line = UpdateNotice::line(true);

        $this->assertStringContainsString('99.5.0', (string) $line);
        $this->assertFileDoesNotExist(Settings::storageDir() . '/cli-update.json');
    }

    public function testACoolingGitHubClientKeepsTheSavedAnswer(): void
    {
        $this->writeCache(time() - 90000, '97.0.0');
        file_put_contents(Settings::storageDir() . '/gh_backoff.json', json_encode(['until' => time() + 3600]));
        $calls = 0;
        Shell::fake(function () use (&$calls): array {
            $calls++;
            return ['code' => 0, 'stdout' => '{"tag_name":"v99.0.0"}', 'stderr' => ''];
        });

        $line = UpdateNotice::line(true);

        $this->assertSame(0, $calls);
        $this->assertStringContainsString('97.0.0', (string) $line);
    }

    private function writeCache(int $checkedAt, string $latest): void
    {
        file_put_contents(Settings::storageDir() . '/cli-update.json', json_encode([
            'checked_at' => $checkedAt,
            'latest' => $latest,
        ]));
    }
}
