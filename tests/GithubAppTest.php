<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GithubAppTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runnerdeck-gha-' . uniqid();
        mkdir($this->dir, 0700, true);
        putenv('RUNNERDECK_SETTINGS_FILE=' . $this->dir . '/settings.json');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE=' . $this->dir . '/app.pem');
        putenv('RUNNERDECK_GITHUB_APP_ID');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID');
        \RunnerDeck\GithubApp::fakeToken(null);
    }

    protected function tearDown(): void
    {
        \RunnerDeck\GithubApp::fakeToken(null);
        \RunnerDeck\Shell::fake(null);
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_GITHUB_APP_KEY_FILE');
        putenv('RUNNERDECK_GITHUB_APP_ID');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID');
        putenv('GH_TOKEN');
        putenv('GITHUB_TOKEN');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function testNotConfiguredWithoutIdsAndKey(): void
    {
        $this->assertFalse(\RunnerDeck\GithubApp::isConfigured());
    }

    #[RunInSeparateProcess]
    public function testCheckLoginUsesAppTokenInsteadOfGhAuthStatus(): void
    {
        putenv('RUNNERDECK_GITHUB_APP_ID=12');
        putenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID=34');
        file_put_contents(
            $this->dir . '/app.pem',
            "-----BEGIN PRIVATE KEY-----\ndGVzdA==\n-----END PRIVATE KEY-----\n"
        );
        \RunnerDeck\GithubApp::fakeToken(fn () => 'ghs_test_token');
        $authStatus = 0;
        \RunnerDeck\Shell::fake(function (array $cmd) use (&$authStatus): array {
            if (in_array('auth', $cmd, true) && in_array('status', $cmd, true)) {
                $authStatus++;
            }
            return ['code' => 1, 'stdout' => '', 'stderr' => 'not logged in'];
        });

        $login = \RunnerDeck\GithubClient::checkLogin();
        $this->assertTrue($login['logged_in']);
        $this->assertSame(0, $authStatus);
        $this->assertSame('ghs_test_token', getenv('GH_TOKEN'));
    }

    #[RunInSeparateProcess]
    public function testSavePrivateKeyRejectsNonPem(): void
    {
        $this->assertFalse(\RunnerDeck\GithubApp::savePrivateKey('not-a-key'));
        $this->assertFalse(\RunnerDeck\GithubApp::hasPrivateKey());
    }
}
