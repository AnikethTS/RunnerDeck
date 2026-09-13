<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private string $lockoutFile;

    protected function setUp(): void
    {
        $settingsFile = sys_get_temp_dir() . '/runnerdeck-auth-test-' . uniqid() . '/settings.json';
        putenv('RUNNERDECK_SETTINGS_FILE=' . $settingsFile);
        $this->lockoutFile = dirname($settingsFile) . '/auth_lockout.json';
    }

    protected function tearDown(): void
    {
        $dir = dirname((string) getenv('RUNNERDECK_SETTINGS_FILE'));
        putenv('RUNNERDECK_SETTINGS_FILE');
        putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        @unlink($this->lockoutFile);
        @unlink($dir . '/settings.json');
        @rmdir($dir);
    }

    private function setSecret(): string
    {
        $secret = \Totp::generateSecret();
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . $secret);
        return $secret;
    }

    #[RunInSeparateProcess]
    public function testIsEnabledFalseByDefault(): void
    {
        $this->assertFalse(\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testIsLoggedInFalseInitially(): void
    {
        $this->assertFalse(\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAttemptFailsWithoutConfiguredSecret(): void
    {
        $this->assertFalse(\Auth::attempt('123456'));
    }

    #[RunInSeparateProcess]
    public function testAttemptSucceedsWithCorrectCode(): void
    {
        $secret = $this->setSecret();
        $code = \Totp::code($secret);

        $this->assertTrue(\Auth::attempt($code));
        $this->assertTrue(\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAttemptFailsWithWrongCode(): void
    {
        $this->setSecret();

        $this->assertFalse(\Auth::attempt('000000'));
        $this->assertFalse(\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testLockoutAfterMaxFailures(): void
    {
        $secret = $this->setSecret();
        for ($i = 0; $i < 5; $i++) {
            \Auth::attempt('000000');
        }

        $this->assertFalse(\Auth::attempt(\Totp::code($secret)));
        $this->assertTrue(\Auth::lockoutStatus()['locked']);
    }

    #[RunInSeparateProcess]
    public function testLockoutClearsOnSuccessBeforeThreshold(): void
    {
        $secret = $this->setSecret();
        \Auth::attempt('000000');
        \Auth::attempt('000000');
        \Auth::attempt('000000');
        $this->assertTrue(\Auth::attempt(\Totp::code($secret)));

        \Auth::attempt('000000');
        \Auth::attempt('000000');
        $this->assertFalse(\Auth::lockoutStatus()['locked']);
    }

    #[RunInSeparateProcess]
    public function testLoginRegeneratesSessionId(): void
    {
        $secret = $this->setSecret();
        \Auth::isLoggedIn();
        $before = session_id();

        \Auth::attempt(\Totp::code($secret));

        $this->assertNotSame($before, session_id());
    }

    #[RunInSeparateProcess]
    public function testLogoutClearsSession(): void
    {
        $secret = $this->setSecret();
        \Auth::attempt(\Totp::code($secret));
        $this->assertTrue(\Auth::isLoggedIn());

        \Auth::logout();

        $this->assertFalse(\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testBeginTotpSetupReturnsAValidSecretWithoutSavingIt(): void
    {
        $secret = \Auth::beginTotpSetup();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertFalse(\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupSavesAndLogsIn(): void
    {
        $secret = \Auth::beginTotpSetup();
        $code = \Totp::code($secret);

        $this->assertTrue(\Auth::confirmTotpSetup($code));
        $this->assertSame($secret, \Settings::load()['RUNNERDECK_AUTH_TOTP_SECRET']);
        $this->assertTrue(\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupRejectsWrongCode(): void
    {
        \Auth::beginTotpSetup();

        $this->assertFalse(\Auth::confirmTotpSetup('000000'));
        $this->assertFalse(\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupWithoutBeginFails(): void
    {
        $this->assertFalse(\Auth::confirmTotpSetup('123456'));
    }

    #[RunInSeparateProcess]
    public function testSaveTotpSecretPreservesOtherSettings(): void
    {
        \Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'my-org']);

        \Auth::saveTotpSecret('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');

        $loaded = \Settings::load();
        $this->assertSame('my-org', $loaded['RUNNERDECK_ORG']);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $loaded['RUNNERDECK_AUTH_TOTP_SECRET']);
    }
}
