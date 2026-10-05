<?php

declare(strict_types=1);

namespace RunnerDeck;

final class Auth
{
    private const SESSION_KEY = 'authenticated';
    private const LAST_ACTIVITY_KEY = 'last_activity';
    private const SESSION_EPOCH_KEY = 'session_epoch';
    private const UA_HASH_KEY = 'ua_hash';
    private const PENDING_SECRET_KEY = 'pending_totp_secret';
    private const ISSUED_RECOVERY_KEY = 'issued_recovery_codes';
    private const LOCKOUT_FILE = 'auth_lockout.json';
    private const RECOVERY_FILE = 'auth_recovery.json';
    private const SESSION_FILE = 'auth_session.json';
    private const MAX_FAILURES = 5;
    private const LOCKOUT_SECONDS = 300;
    private const RECOVERY_COUNT = 8;

    private static function ensureSession(): void
    {
        Session::start();
    }

    private static function lockoutPath(): string
    {
        return Settings::storageDir() . '/' . self::LOCKOUT_FILE;
    }

    private static function recoveryPath(): string
    {
        return Settings::storageDir() . '/' . self::RECOVERY_FILE;
    }

    private static function sessionMetaPath(): string
    {
        return Settings::storageDir() . '/' . self::SESSION_FILE;
    }

    /** @return array<string, int> */
    private static function readLockoutFile(): array
    {
        $path = self::lockoutPath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($path) ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, int> $data */
    private static function writeLockoutFile(array $data): void
    {
        self::writePrivateJson(self::lockoutPath(), $data);
    }

    /** @param array<string, mixed> $data */
    private static function writePrivateJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    public static function isEnabled(): bool
    {
        return Config::authEnabled();
    }

    /** Loopback with login off: full access. Anything else needs a TOTP session. */
    public static function anonymousAccessAllowed(): bool
    {
        return !self::isEnabled() && Session::remoteIsLoopback();
    }

    public static function requirePageAccess(): void
    {
        if (self::isLoggedIn() || self::anonymousAccessAllowed()) {
            return;
        }
        if (self::isEnabled()) {
            header('Location: login.php');
            exit;
        }
        self::sendSetupRequired();
    }

    public static function rejectApiIfProtected(): void
    {
        if (self::isLoggedIn() || self::anonymousAccessAllowed()) {
            return;
        }
        if (self::isEnabled()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'authentication required']);
            exit;
        }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'message' => 'TOTP login is required off loopback. Run php bin/setup-totp.php on the server.',
        ]);
        exit;
    }

    public static function rejectPlainIfProtected(): void
    {
        if (self::isLoggedIn() || self::anonymousAccessAllowed()) {
            return;
        }
        if (self::isEnabled()) {
            http_response_code(401);
            header('Content-Type: text/plain');
            echo 'unauthorized';
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'TOTP login is required off loopback. Run php bin/setup-totp.php on the server.';
        exit;
    }

    public static function sendSetupRequired(): never
    {
        http_response_code(403);
        Layout::htmlOpen('RunnerDeck — Login required');
        Layout::topbarStart();
        Layout::topbarEndStart();
        Layout::topbarEnd();
        echo '  <main class="setup-main"><div class="setup-card">';
        echo '<h2>Login required</h2>';
        echo '<p class="muted">This request is not from loopback, and TOTP login is not configured. ';
        echo 'Anyone who can reach this port would have full control of the runners. ';
        echo 'Set up login from a shell on this machine, then reload:</p>';
        echo '<p><code>php bin/setup-totp.php</code></p>';
        echo '</div></main>';
        Layout::htmlClose(['assets/js/theme.js']);
        exit;
    }

    public static function isLoggedIn(): bool
    {
        self::ensureSession();
        if (($_SESSION[self::SESSION_KEY] ?? false) !== true) {
            return false;
        }

        $epoch = self::sessionEpoch();
        if ((int) ($_SESSION[self::SESSION_EPOCH_KEY] ?? 0) !== $epoch) {
            self::logout();
            return false;
        }

        $uaHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $stored = (string) ($_SESSION[self::UA_HASH_KEY] ?? '');
        if ($stored === '' || !hash_equals($stored, $uaHash)) {
            self::logout();
            return false;
        }

        $idleMinutes = Config::sessionIdleMinutes();
        if ($idleMinutes !== null) {
            $last = (int) ($_SESSION[self::LAST_ACTIVITY_KEY] ?? 0);
            if ($last > 0 && (time() - $last) > ($idleMinutes * 60)) {
                self::logout();
                return false;
            }
        }

        $_SESSION[self::LAST_ACTIVITY_KEY] = time();
        return true;
    }

    /** @return array{locked: bool, retryAfter?: int} */
    public static function lockoutStatus(): array
    {
        $lockedUntil = (int) (self::readLockoutFile()['lockedUntil'] ?? 0);
        if ($lockedUntil > time()) {
            return ['locked' => true, 'retryAfter' => $lockedUntil - time()];
        }
        return ['locked' => false];
    }

    public static function attempt(string $code): bool
    {
        self::ensureSession();
        if (!self::isEnabled() || self::lockoutStatus()['locked']) {
            return false;
        }

        $secret = Config::authTotpSecret();
        $ok = $secret !== null && Totp::verify($secret, $code);
        self::recordAttempt($ok);
        if ($ok) {
            self::login();
        }
        return $ok;
    }

    public static function attemptRecovery(string $code): bool
    {
        self::ensureSession();
        if (!self::isEnabled() || self::lockoutStatus()['locked']) {
            return false;
        }

        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '') {
            self::recordAttempt(false);
            return false;
        }

        $hashes = self::readRecoveryHashes();
        $matched = null;
        foreach ($hashes as $i => $hash) {
            if (password_verify($normalized, $hash)) {
                $matched = $i;
                break;
            }
        }
        if ($matched === null) {
            self::recordAttempt(false);
            return false;
        }

        unset($hashes[$matched]);
        self::writeRecoveryHashes(array_values($hashes));
        self::recordAttempt(true);
        self::login();
        AppLog::info('auth.recovery', 'signed in with a recovery code (' . count($hashes) . ' remaining)');
        return true;
    }

    public static function login(): void
    {
        self::ensureSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION[self::LAST_ACTIVITY_KEY] = time();
        $_SESSION[self::SESSION_EPOCH_KEY] = self::sessionEpoch();
        $_SESSION[self::UA_HASH_KEY] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function saveTotpSecret(string $secret): void
    {
        Settings::save(array_merge(Settings::load(), ['RUNNERDECK_AUTH_TOTP_SECRET' => $secret]));
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . $secret);
        @unlink(self::lockoutPath());
    }

    public static function beginTotpSetup(): string
    {
        self::ensureSession();
        $secret = Totp::generateSecret();
        $_SESSION[self::PENDING_SECRET_KEY] = $secret;
        return $secret;
    }

    public static function pendingTotpSecret(): ?string
    {
        self::ensureSession();
        $secret = $_SESSION[self::PENDING_SECRET_KEY] ?? null;
        return is_string($secret) ? $secret : null;
    }

    public static function confirmTotpSetup(string $code): bool
    {
        self::ensureSession();
        $secret = $_SESSION[self::PENDING_SECRET_KEY] ?? null;
        if (!is_string($secret) || !Totp::verify($secret, $code)) {
            return false;
        }
        self::saveTotpSecret($secret);
        unset($_SESSION[self::PENDING_SECRET_KEY]);
        self::issueRecoveryCodes();
        self::login();
        return true;
    }

    /** @return list<string> plaintext codes, shown once */
    public static function issueRecoveryCodes(): array
    {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $hashes[] = password_hash($raw, PASSWORD_DEFAULT);
        }
        self::writeRecoveryHashes($hashes);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::ISSUED_RECOVERY_KEY] = $codes;
        }
        return $codes;
    }

    /** @return list<string> */
    public static function takeIssuedRecoveryCodes(): array
    {
        self::ensureSession();
        $codes = $_SESSION[self::ISSUED_RECOVERY_KEY] ?? [];
        unset($_SESSION[self::ISSUED_RECOVERY_KEY]);
        if (!is_array($codes)) {
            return [];
        }
        $out = [];
        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $out[] = $code;
            }
        }
        return $out;
    }

    public static function recoveryCodesRemaining(): int
    {
        return count(self::readRecoveryHashes());
    }

    public static function revokeOtherSessions(): void
    {
        $epoch = self::sessionEpoch() + 1;
        self::writePrivateJson(self::sessionMetaPath(), ['epoch' => $epoch]);
        self::ensureSession();
        $_SESSION[self::SESSION_EPOCH_KEY] = $epoch;
        AppLog::info('auth.revoke_sessions', 'session epoch bumped to ' . $epoch);
    }

    public static function sessionEpoch(): int
    {
        $path = self::sessionMetaPath();
        if (!is_file($path)) {
            return 0;
        }
        $decoded = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($decoded)) {
            return 0;
        }
        return (int) ($decoded['epoch'] ?? 0);
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $code) ?? '');
    }

    /** @return list<string> */
    private static function readRecoveryHashes(): array
    {
        $path = self::recoveryPath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($decoded) || !isset($decoded['hashes']) || !is_array($decoded['hashes'])) {
            return [];
        }
        $out = [];
        foreach ($decoded['hashes'] as $hash) {
            if (is_string($hash) && $hash !== '') {
                $out[] = $hash;
            }
        }
        return $out;
    }

    /** @param list<string> $hashes */
    private static function writeRecoveryHashes(array $hashes): void
    {
        self::writePrivateJson(self::recoveryPath(), ['hashes' => $hashes]);
    }

    private static function recordAttempt(bool $success): void
    {
        if ($success) {
            @unlink(self::lockoutPath());
            return;
        }

        $data = self::readLockoutFile();
        $lockedUntil = (int) ($data['lockedUntil'] ?? 0);
        $failures = ($lockedUntil > 0 && $lockedUntil <= time()) ? 1 : (int) ($data['failures'] ?? 0) + 1;

        $write = ['failures' => $failures];
        if ($failures >= self::MAX_FAILURES) {
            $write['lockedUntil'] = time() + self::LOCKOUT_SECONDS;
        }
        self::writeLockoutFile($write);
    }
}
