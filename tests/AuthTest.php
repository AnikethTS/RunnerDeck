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
        putenv('RUNNERDECK_SESSION_IDLE_MINUTES');
        putenv('RUNNERDECK_IP_ALLOWLIST');
        putenv('RUNNERDECK_REQUIRE_MTLS');
        \RunnerDeck\Core\ClientGate::$denyHandler = null;
        @unlink($this->lockoutFile);
        @unlink($dir . '/auth_recovery.json');
        @unlink($dir . '/auth_session.json');
        @unlink($dir . '/settings.json');
        @rmdir($dir);
    }

    private function setSecret(): string
    {
        $secret = \RunnerDeck\Core\Totp::generateSecret();
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . $secret);
        return $secret;
    }

    #[RunInSeparateProcess]
    public function testIsEnabledFalseByDefault(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testIsLoggedInFalseInitially(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAttemptFailsWithoutConfiguredSecret(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Auth::attempt('123456'));
    }

    #[RunInSeparateProcess]
    public function testAttemptSucceedsWithCorrectCode(): void
    {
        $secret = $this->setSecret();
        $code = \RunnerDeck\Core\Totp::code($secret);

        $this->assertTrue(\RunnerDeck\Core\Auth::attempt($code));
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAttemptFailsWithWrongCode(): void
    {
        $this->setSecret();

        $this->assertFalse(\RunnerDeck\Core\Auth::attempt('000000'));
        $this->assertFalse(\RunnerDeck\Core\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testLockoutAfterMaxFailures(): void
    {
        $secret = $this->setSecret();
        for ($i = 0; $i < 5; $i++) {
            \RunnerDeck\Core\Auth::attempt('000000');
        }

        $this->assertFalse(\RunnerDeck\Core\Auth::attempt(\RunnerDeck\Core\Totp::code($secret)));
        $this->assertTrue(\RunnerDeck\Core\Auth::lockoutStatus()['locked']);
    }

    #[RunInSeparateProcess]
    public function testLockoutClearsOnSuccessBeforeThreshold(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Core\Auth::attempt('000000');
        \RunnerDeck\Core\Auth::attempt('000000');
        \RunnerDeck\Core\Auth::attempt('000000');
        $this->assertTrue(\RunnerDeck\Core\Auth::attempt(\RunnerDeck\Core\Totp::code($secret)));

        \RunnerDeck\Core\Auth::attempt('000000');
        \RunnerDeck\Core\Auth::attempt('000000');
        $this->assertFalse(\RunnerDeck\Core\Auth::lockoutStatus()['locked']);
    }

    #[RunInSeparateProcess]
    public function testLoginRegeneratesSessionId(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Core\Auth::isLoggedIn();
        $before = session_id();

        \RunnerDeck\Core\Auth::attempt(\RunnerDeck\Core\Totp::code($secret));

        $this->assertNotSame($before, session_id());
    }

    #[RunInSeparateProcess]
    public function testLogoutClearsSession(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Core\Auth::attempt(\RunnerDeck\Core\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());

        \RunnerDeck\Core\Auth::logout();

        $this->assertFalse(\RunnerDeck\Core\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testBeginTotpSetupReturnsAValidSecretWithoutSavingIt(): void
    {
        $secret = \RunnerDeck\Core\Auth::beginTotpSetup();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertFalse(\RunnerDeck\Core\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupSavesAndLogsIn(): void
    {
        $secret = \RunnerDeck\Core\Auth::beginTotpSetup();
        $code = \RunnerDeck\Core\Totp::code($secret);

        $this->assertTrue(\RunnerDeck\Core\Auth::confirmTotpSetup($code));
        $this->assertSame($secret, \RunnerDeck\Core\Settings::load()['RUNNERDECK_AUTH_TOTP_SECRET']);
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupRejectsWrongCode(): void
    {
        \RunnerDeck\Core\Auth::beginTotpSetup();

        $this->assertFalse(\RunnerDeck\Core\Auth::confirmTotpSetup('000000'));
        $this->assertFalse(\RunnerDeck\Core\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupWithoutBeginFails(): void
    {
        $this->assertFalse(\RunnerDeck\Core\Auth::confirmTotpSetup('123456'));
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretNullBeforeBegin(): void
    {
        $this->assertNull(\RunnerDeck\Core\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretReflectsBegin(): void
    {
        $secret = \RunnerDeck\Core\Auth::beginTotpSetup();

        $this->assertSame($secret, \RunnerDeck\Core\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretClearedAfterConfirm(): void
    {
        $secret = \RunnerDeck\Core\Auth::beginTotpSetup();
        \RunnerDeck\Core\Auth::confirmTotpSetup(\RunnerDeck\Core\Totp::code($secret));

        $this->assertNull(\RunnerDeck\Core\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testSaveTotpSecretPreservesOtherSettings(): void
    {
        \RunnerDeck\Core\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'my-org']);

        \RunnerDeck\Core\Auth::saveTotpSecret('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');

        $loaded = \RunnerDeck\Core\Settings::load();
        $this->assertSame('my-org', $loaded['RUNNERDECK_ORG']);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $loaded['RUNNERDECK_AUTH_TOTP_SECRET']);
    }

    #[RunInSeparateProcess]
    public function testIdleTimeoutLogsOutAfterInactivity(): void
    {
        $secret = $this->setSecret();
        putenv('RUNNERDECK_SESSION_IDLE_MINUTES=1');
        \RunnerDeck\Core\Auth::attempt(\RunnerDeck\Core\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());

        $_SESSION['last_activity'] = time() - 61;
        $this->assertFalse(\RunnerDeck\Core\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testIdleTimeoutOffKeepsOldActivity(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Core\Auth::attempt(\RunnerDeck\Core\Totp::code($secret));
        $_SESSION['last_activity'] = time() - 86400;
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAnonymousAccessAllowedOnlyOnLoopbackWithoutTotp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertTrue(\RunnerDeck\Core\Auth::anonymousAccessAllowed());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertFalse(\RunnerDeck\Core\Auth::anonymousAccessAllowed());

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->setSecret();
        $this->assertFalse(\RunnerDeck\Core\Auth::anonymousAccessAllowed());
    }

    #[RunInSeparateProcess]
    public function testRecoveryCodeWorksOnce(): void
    {
        $secret = \RunnerDeck\Core\Auth::beginTotpSetup();
        $this->assertTrue(\RunnerDeck\Core\Auth::confirmTotpSetup(\RunnerDeck\Core\Totp::code($secret)));
        $codes = \RunnerDeck\Core\Auth::takeIssuedRecoveryCodes();
        $this->assertCount(8, $codes);
        \RunnerDeck\Core\Auth::logout();

        $this->assertTrue(\RunnerDeck\Core\Auth::attemptRecovery($codes[0]));
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());
        \RunnerDeck\Core\Auth::logout();
        $this->assertFalse(\RunnerDeck\Core\Auth::attemptRecovery($codes[0]));
        $this->assertSame(7, \RunnerDeck\Core\Auth::recoveryCodesRemaining());
    }

    #[RunInSeparateProcess]
    public function testRevokeOtherSessionsInvalidatesOldEpoch(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Core\Auth::attempt(\RunnerDeck\Core\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());

        \RunnerDeck\Core\Auth::revokeOtherSessions();
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());

        $_SESSION['session_epoch'] = 0;
        $this->assertFalse(\RunnerDeck\Core\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testRequirePageAccessDeniesPublicHostBeforeLoginRedirect(): void
    {
        $this->setSecret();
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $format = null;
        \RunnerDeck\Core\ClientGate::$denyHandler = static function (string $fmt) use (&$format): void {
            $format = $fmt;
            throw new \RuntimeException('client-gate-denied');
        };
        try {
            \RunnerDeck\Core\Auth::requirePageAccess();
            $this->fail('expected extra-gate deny before login redirect');
        } catch (\RuntimeException $e) {
            $this->assertSame('client-gate-denied', $e->getMessage());
        }
        $this->assertSame('html', $format);
    }

    #[RunInSeparateProcess]
    public function testRejectApiIfProtectedDeniesJsonOnPublicHost(): void
    {
        $this->setSecret();
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $format = null;
        \RunnerDeck\Core\ClientGate::$denyHandler = static function (string $fmt) use (&$format): void {
            $format = $fmt;
            throw new \RuntimeException('client-gate-denied');
        };
        try {
            \RunnerDeck\Core\Auth::rejectApiIfProtected();
            $this->fail('expected extra-gate deny');
        } catch (\RuntimeException $e) {
            $this->assertSame('client-gate-denied', $e->getMessage());
        }
        $this->assertSame('json', $format);
    }

    #[RunInSeparateProcess]
    public function testRejectPlainIfProtectedDeniesOnPublicHost(): void
    {
        $this->setSecret();
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $format = null;
        \RunnerDeck\Core\ClientGate::$denyHandler = static function (string $fmt) use (&$format): void {
            $format = $fmt;
            throw new \RuntimeException('client-gate-denied');
        };
        try {
            \RunnerDeck\Core\Auth::rejectPlainIfProtected();
            $this->fail('expected extra-gate deny');
        } catch (\RuntimeException $e) {
            $this->assertSame('client-gate-denied', $e->getMessage());
        }
        $this->assertSame('plain', $format);
    }

    #[RunInSeparateProcess]
    public function testUserAgentMismatchLogsOut(): void
    {
        $secret = $this->setSecret();
        $_SERVER['HTTP_USER_AGENT'] = 'operator';
        \RunnerDeck\Core\Auth::attempt(\RunnerDeck\Core\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Core\Auth::isLoggedIn());

        $_SERVER['HTTP_USER_AGENT'] = 'other';
        $this->assertFalse(\RunnerDeck\Core\Auth::isLoggedIn());
    }
}
