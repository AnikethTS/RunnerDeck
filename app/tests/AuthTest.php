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
        \RunnerDeck\Security\ClientGate::$denyHandler = null;
        @unlink($this->lockoutFile);
        @unlink($dir . '/auth_recovery.json');
        @unlink($dir . '/auth_session.json');
        @unlink($dir . '/settings.json');
        @rmdir($dir);
    }

    private function setSecret(): string
    {
        $secret = \RunnerDeck\Security\Totp::generateSecret();
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . $secret);
        return $secret;
    }

    #[RunInSeparateProcess]
    public function testIsEnabledFalseByDefault(): void
    {
        $this->assertFalse(\RunnerDeck\Security\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testIsLoggedInFalseInitially(): void
    {
        $this->assertFalse(\RunnerDeck\Security\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAttemptFailsWithoutConfiguredSecret(): void
    {
        $this->assertFalse(\RunnerDeck\Security\Auth::attempt('123456'));
    }

    #[RunInSeparateProcess]
    public function testAttemptSucceedsWithCorrectCode(): void
    {
        $secret = $this->setSecret();
        $code = \RunnerDeck\Security\Totp::code($secret);

        $this->assertTrue(\RunnerDeck\Security\Auth::attempt($code));
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAttemptFailsWithWrongCode(): void
    {
        $this->setSecret();

        $this->assertFalse(\RunnerDeck\Security\Auth::attempt('000000'));
        $this->assertFalse(\RunnerDeck\Security\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testLockoutAfterMaxFailures(): void
    {
        $secret = $this->setSecret();
        for ($i = 0; $i < 5; $i++) {
            \RunnerDeck\Security\Auth::attempt('000000');
        }

        $this->assertFalse(\RunnerDeck\Security\Auth::attempt(\RunnerDeck\Security\Totp::code($secret)));
        $this->assertTrue(\RunnerDeck\Security\Auth::lockoutStatus()['locked']);
    }

    #[RunInSeparateProcess]
    public function testLockoutClearsOnSuccessBeforeThreshold(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Security\Auth::attempt('000000');
        \RunnerDeck\Security\Auth::attempt('000000');
        \RunnerDeck\Security\Auth::attempt('000000');
        $this->assertTrue(\RunnerDeck\Security\Auth::attempt(\RunnerDeck\Security\Totp::code($secret)));

        \RunnerDeck\Security\Auth::attempt('000000');
        \RunnerDeck\Security\Auth::attempt('000000');
        $this->assertFalse(\RunnerDeck\Security\Auth::lockoutStatus()['locked']);
    }

    #[RunInSeparateProcess]
    public function testLoginRegeneratesSessionId(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Security\Auth::isLoggedIn();
        $before = session_id();

        \RunnerDeck\Security\Auth::attempt(\RunnerDeck\Security\Totp::code($secret));

        $this->assertNotSame($before, session_id());
    }

    #[RunInSeparateProcess]
    public function testLogoutClearsSession(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Security\Auth::attempt(\RunnerDeck\Security\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());

        \RunnerDeck\Security\Auth::logout();

        $this->assertFalse(\RunnerDeck\Security\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testBeginTotpSetupReturnsAValidSecretWithoutSavingIt(): void
    {
        $secret = \RunnerDeck\Security\Auth::beginTotpSetup();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertFalse(\RunnerDeck\Security\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupSavesAndLogsIn(): void
    {
        $secret = \RunnerDeck\Security\Auth::beginTotpSetup();
        $code = \RunnerDeck\Security\Totp::code($secret);

        $this->assertTrue(\RunnerDeck\Security\Auth::confirmTotpSetup($code));
        $this->assertSame($secret, \RunnerDeck\Configuration\Settings::load()['RUNNERDECK_AUTH_TOTP_SECRET']);
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupRejectsWrongCode(): void
    {
        \RunnerDeck\Security\Auth::beginTotpSetup();

        $this->assertFalse(\RunnerDeck\Security\Auth::confirmTotpSetup('000000'));
        $this->assertFalse(\RunnerDeck\Security\Auth::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testConfirmTotpSetupWithoutBeginFails(): void
    {
        $this->assertFalse(\RunnerDeck\Security\Auth::confirmTotpSetup('123456'));
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretNullBeforeBegin(): void
    {
        $this->assertNull(\RunnerDeck\Security\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretReflectsBegin(): void
    {
        $secret = \RunnerDeck\Security\Auth::beginTotpSetup();

        $this->assertSame($secret, \RunnerDeck\Security\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testPendingTotpSecretClearedAfterConfirm(): void
    {
        $secret = \RunnerDeck\Security\Auth::beginTotpSetup();
        \RunnerDeck\Security\Auth::confirmTotpSetup(\RunnerDeck\Security\Totp::code($secret));

        $this->assertNull(\RunnerDeck\Security\Auth::pendingTotpSecret());
    }

    #[RunInSeparateProcess]
    public function testSaveTotpSecretPreservesOtherSettings(): void
    {
        \RunnerDeck\Configuration\Settings::save(['RUNNERDECK_SCOPE' => 'org', 'RUNNERDECK_ORG' => 'my-org']);

        \RunnerDeck\Security\Auth::saveTotpSecret('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');

        $loaded = \RunnerDeck\Configuration\Settings::load();
        $this->assertSame('my-org', $loaded['RUNNERDECK_ORG']);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $loaded['RUNNERDECK_AUTH_TOTP_SECRET']);
    }

    #[RunInSeparateProcess]
    public function testIdleTimeoutLogsOutAfterInactivity(): void
    {
        $secret = $this->setSecret();
        putenv('RUNNERDECK_SESSION_IDLE_MINUTES=1');
        \RunnerDeck\Security\Auth::attempt(\RunnerDeck\Security\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());

        $_SESSION['last_activity'] = time() - 61;
        $this->assertFalse(\RunnerDeck\Security\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testIdleTimeoutOffKeepsOldActivity(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Security\Auth::attempt(\RunnerDeck\Security\Totp::code($secret));
        $_SESSION['last_activity'] = time() - 86400;
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testAnonymousAccessAllowedOnlyOnLoopbackWithoutTotp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertTrue(\RunnerDeck\Security\Auth::anonymousAccessAllowed());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertFalse(\RunnerDeck\Security\Auth::anonymousAccessAllowed());

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->setSecret();
        $this->assertFalse(\RunnerDeck\Security\Auth::anonymousAccessAllowed());
    }

    #[RunInSeparateProcess]
    public function testRecoveryCodeWorksOnce(): void
    {
        $secret = \RunnerDeck\Security\Auth::beginTotpSetup();
        $this->assertTrue(\RunnerDeck\Security\Auth::confirmTotpSetup(\RunnerDeck\Security\Totp::code($secret)));
        $codes = \RunnerDeck\Security\Auth::takeIssuedRecoveryCodes();
        $this->assertCount(8, $codes);
        \RunnerDeck\Security\Auth::logout();

        $this->assertTrue(\RunnerDeck\Security\Auth::attemptRecovery($codes[0]));
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());
        \RunnerDeck\Security\Auth::logout();
        $this->assertFalse(\RunnerDeck\Security\Auth::attemptRecovery($codes[0]));
        $this->assertSame(7, \RunnerDeck\Security\Auth::recoveryCodesRemaining());
    }

    #[RunInSeparateProcess]
    public function testRevokeOtherSessionsInvalidatesOldEpoch(): void
    {
        $secret = $this->setSecret();
        \RunnerDeck\Security\Auth::attempt(\RunnerDeck\Security\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());

        \RunnerDeck\Security\Auth::revokeOtherSessions();
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());

        $_SESSION['session_epoch'] = 0;
        $this->assertFalse(\RunnerDeck\Security\Auth::isLoggedIn());
    }

    #[RunInSeparateProcess]
    public function testRequirePageAccessDeniesPublicHostBeforeLoginRedirect(): void
    {
        $this->setSecret();
        $_SERVER['HTTP_HOST'] = 'runnerdeck.example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $format = null;
        \RunnerDeck\Security\ClientGate::$denyHandler = static function (string $fmt) use (&$format): void {
            $format = $fmt;
            throw new \RuntimeException('client-gate-denied');
        };
        try {
            \RunnerDeck\Security\Auth::requirePageAccess();
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
        \RunnerDeck\Security\ClientGate::$denyHandler = static function (string $fmt) use (&$format): void {
            $format = $fmt;
            throw new \RuntimeException('client-gate-denied');
        };
        try {
            \RunnerDeck\Security\Auth::rejectApiIfProtected();
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
        \RunnerDeck\Security\ClientGate::$denyHandler = static function (string $fmt) use (&$format): void {
            $format = $fmt;
            throw new \RuntimeException('client-gate-denied');
        };
        try {
            \RunnerDeck\Security\Auth::rejectPlainIfProtected();
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
        \RunnerDeck\Security\Auth::attempt(\RunnerDeck\Security\Totp::code($secret));
        $this->assertTrue(\RunnerDeck\Security\Auth::isLoggedIn());

        $_SERVER['HTTP_USER_AGENT'] = 'other';
        $this->assertFalse(\RunnerDeck\Security\Auth::isLoggedIn());
    }
}
