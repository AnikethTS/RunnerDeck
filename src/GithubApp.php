<?php

declare(strict_types=1);

namespace RunnerDeck;

final class GithubApp
{
    private const KEY_FILE = 'github_app.pem';
    private const TOKEN_FILE = 'github_app_token.json';
    private const TIMEOUT_SECONDS = 10;

    /** @var (callable(): ?string)|null */
    private static $tokenFake = null;

    public static function fakeToken(?callable $fn): void
    {
        self::$tokenFake = $fn;
    }

    public static function isConfigured(): bool
    {
        return self::appId() !== null
            && self::installationId() !== null
            && self::privateKey() !== null;
    }

    public static function appId(): ?int
    {
        return self::positiveId(getenv('RUNNERDECK_GITHUB_APP_ID'));
    }

    public static function installationId(): ?int
    {
        return self::positiveId(getenv('RUNNERDECK_GITHUB_APP_INSTALLATION_ID'));
    }

    public static function hasPrivateKey(): bool
    {
        return self::privateKey() !== null;
    }

    /** Put GH_TOKEN in the environment for `gh api` when the app is configured. */
    public static function applyToEnv(): void
    {
        if (!self::isConfigured()) {
            return;
        }
        $token = self::token();
        if ($token === null || $token === '') {
            return;
        }
        putenv('GH_TOKEN=' . $token);
        putenv('GITHUB_TOKEN=' . $token);
    }

    public static function token(): ?string
    {
        if (self::$tokenFake !== null) {
            return (self::$tokenFake)();
        }
        $cached = self::cachedToken();
        if ($cached !== null) {
            return $cached;
        }
        $minted = self::mintInstallationToken();
        if ($minted === null) {
            return null;
        }
        self::writeTokenCache($minted['token'], $minted['expires_at']);
        return $minted['token'];
    }

    public static function savePrivateKey(string $pem): bool
    {
        $pem = trim($pem);
        if ($pem === '' || !self::looksLikePem($pem)) {
            return false;
        }
        $path = self::keyPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $pem . "\n");
        chmod($tmp, 0600);
        return rename($tmp, $path);
    }

    private static function looksLikePem(string $pem): bool
    {
        return str_contains($pem, 'BEGIN') && str_contains($pem, 'PRIVATE KEY');
    }

    private static function privateKey(): ?string
    {
        $path = self::keyPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = trim((string) file_get_contents($path));
        return $raw !== '' && self::looksLikePem($raw) ? $raw : null;
    }

    /**
     * @return array{token: string, expires_at: int}|null
     */
    private static function mintInstallationToken(): ?array
    {
        $jwt = self::jwt();
        $install = self::installationId();
        if ($jwt === null || $install === null) {
            return null;
        }
        $url = 'https://api.github.com/app/installations/' . $install . '/access_tokens';
        $result = Shell::exec(
            [
                'curl', '-fsS', '-m', (string) self::TIMEOUT_SECONDS,
                '-X', 'POST',
                '-H', 'Authorization: Bearer ' . $jwt,
                '-H', 'Accept: application/vnd.github+json',
                '-H', 'X-GitHub-Api-Version: 2022-11-28',
                '-d', '{}',
                $url,
            ],
            self::TIMEOUT_SECONDS + 2
        );
        if ($result['code'] !== 0) {
            AppLog::errorThrottled('github_app', 'failed to mint installation token', [
                'stderr' => $result['stderr'],
            ]);
            return null;
        }
        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded) || !is_string($decoded['token'] ?? null) || $decoded['token'] === '') {
            return null;
        }
        $expires = strtotime((string) ($decoded['expires_at'] ?? '')) ?: (time() + 3000);
        return ['token' => $decoded['token'], 'expires_at' => $expires];
    }

    private static function jwt(): ?string
    {
        $pem = self::privateKey();
        $appId = self::appId();
        if ($pem === null || $appId === null) {
            return null;
        }
        $now = time();
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES) ?: '');
        $body = self::b64url(json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $appId,
        ], JSON_UNESCAPED_SLASHES) ?: '');
        $signing = $header . '.' . $body;
        $sig = '';
        $ok = openssl_sign($signing, $sig, $pem, OPENSSL_ALGO_SHA256);
        if (!$ok || $sig === '') {
            return null;
        }
        return $signing . '.' . self::b64url($sig);
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function cachedToken(): ?string
    {
        $path = self::tokenPath();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $token = (string) ($decoded['token'] ?? '');
        $expires = (int) ($decoded['expires_at'] ?? 0);
        if ($token === '' || $expires < (time() + 120)) {
            return null;
        }
        return $token;
    }

    private static function writeTokenCache(string $token, int $expiresAt): void
    {
        $path = self::tokenPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode([
            'expires_at' => $expiresAt,
            'token' => $token,
        ], JSON_PRETTY_PRINT));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    private static function positiveId(string|false $raw): ?int
    {
        if ($raw === false || $raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $n = (int) $raw;
        return $n > 0 ? $n : null;
    }

    private static function keyPath(): string
    {
        $override = getenv('RUNNERDECK_GITHUB_APP_KEY_FILE');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return Settings::storageDir() . '/' . self::KEY_FILE;
    }

    private static function tokenPath(): string
    {
        return Settings::storageDir() . '/' . self::TOKEN_FILE;
    }
}
