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
        $secret = \RunnerDeck\Totp::generateSecret();
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . $secret);
        return $secret;
    }

    #[RunInSeparateProcess]
    public function testIsEnabledFalseByDefault(): void
    {
        $this->assertFalse(\RunnerDeck\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testIsLoggedInFalseInitially(): void
    {
        $this->assertFalse(\RunnerDeck\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAttemptFailsWithoutConfiguredSecret(): void
    {
        $this->assertFalse(\RunnerDeck\Auth::attempt('123456'));
    }

    #[RunInSeparateProcess]
    public function testAttemptSucceedsWithCorrectCode(): void
    {
        $secret = $this->setSecret();
        $code = \RunnerDeck\Totp::code($secret);

        $this->assertTrue(\RunnerDeck\Auth::attempt($code));
        $this->assertTrue(\RunnerDeck\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAttemptFailsWithWrongCode(): void
    {
        $this->setSecret();

        $this->assertFalse(\RunnerDeck\Auth::attempt('000000'));
        $this->assertFalse(\RunnerDeck\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testLockoutAfterMaxFailures(): void
    {
        $secret = $this->setSecret();
        for ($i = 0; $i < 5; $i++) {
            \RunnerDeck\Auth::attempt('000000');
        }

        $this->assertFalse(\RunnerDeck\Auth::attempt(\RunnerDeck\Totp::code($secret)));
        $this->assertTrue(\RunnerDeck\Auth::lockoutStatus()['locked']);
    }

    #[RunInSeparateProcess]
    public function testLockoutClearsOnSuccessBeforeThreshold(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Auth::attempt('000000');
        \RunnerDeck\Auth::attempt('000000');
        \RunnerDeck\Auth::attempt('000000');
        $this->assertTrue(\RunnerDeck\Auth::attempt(\RunnerDeck\Totp::code($secret)));

        \RunnerDeck\Auth::attempt('000000');
        \RunnerDeck\Auth::attempt('000000');
        $this->assertFalse(\RunnerDeck\Auth::lockoutStatus()['locked']);
    }

    #[RunInSeparateProcess]
    public function testLoginRegeneratesSessionId(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Auth::isLoggedIn();
        $before = session_id();

        \RunnerDeck\Auth::attempt(\RunnerDeck\Totp::code($secret));

        $this->assertNotSame($before, session_id());
    }

    #[RunInSeparateProcess]
    public function testLogoutClearsSession(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Auth::attempt(\RunnerDeck\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Auth::isLoggedIn());

        \RunnerDeck\Auth::logout();

        $this->assertFalse(\RunnerDeck\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testBeginTotpSetupReturnsAValidSecretWithoutSavingIt(): void
    {
        $secret = \RunnerDeck\Auth::beginTotpSetup();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertFalse(\RunnerDeck\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupSavesAndLogsIn(): void
    {
        $secret = \RunnerDeck\Auth::beginTotpSetup();
        $code = \RunnerDeck\Totp::code($secret);

        $this->assertTrue(\RunnerDeck\Auth::confirmTotpSetup($code));
        $this->assertSame($secret, \RunnerDeck\Settings::load()['RUNNERDECK_AUTH_TOTP_SECRET']);
        $this->assertTrue(\RunnerDeck\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupRejectsWrongCode(): void
    {
        \RunnerDeck\Auth::beginTotpSetup();

        $this->assertFalse(\RunnerDeck\Auth::confirmTotpSetup('000000'));
        $this->assertFalse(\RunnerDeck\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupWithoutBeginFails(): void
    {
        $this->assertFalse(\RunnerDeck\Auth::confirmTotpSetup('123456'));
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretNullBeforeBegin(): void
    {
        $this->assertNull(\RunnerDeck\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretReflectsBegin(): void
    {
        $secret = \RunnerDeck\Auth::beginTotpSetup();

        $this->assertSame($secret, \RunnerDeck\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretClearedAfterConfirm(): void
    {
        $secret = \RunnerDeck\Auth::beginTotpSetup();
        \RunnerDeck\Auth::confirmTotpSetup(\RunnerDeck\Totp::code($secret));

        $this->assertNull(\RunnerDeck\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testSaveTotpSecretPreservesOtherSettings(): void
    {
        \RunnerDeck\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'my-org']);

        \RunnerDeck\Auth::saveTotpSecret('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');

        $loaded = \RunnerDeck\Settings::load();
        $this->assertSame('my-org', $loaded['RUNNERDECK_ORG']);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $loaded['RUNNERDECK_AUTH_TOTP_SECRET']);
    }
}
