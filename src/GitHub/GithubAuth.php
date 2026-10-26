<?php

declare(strict_types=1);

namespace RunnerDeck\GitHub;

final class GithubAuth
{
    /**
     * Specific banner when GitHub auth is expired, SSO-blocked, or missing
     * permissions. Null means use the generic logged-in / not-logged-in text.
     * Unknown errors fail open (no extra banner).
     */
    public static function staleWarning(bool $loggedIn, bool $orgAccessOk, string $message): ?string
    {
        if ($loggedIn && $orgAccessOk) {
            return null;
        }
        if (GithubBackoff::looksLimited($message)) {
            return null;
        }
        if (!self::looksStale($message)) {
            return null;
        }
        if (GithubApp::isConfigured()) {
            return 'GitHub App token cannot read runners (expired, missing permissions, or a bad installation). '
                . 'Check App ID, installation, and private key in Settings.';
        }
        if (!$loggedIn) {
            return 'GitHub authentication is expired or missing. Run `gh auth login`, or set a GitHub App in Settings.';
        }
        return 'gh is logged in, but this token cannot read runners (expired, SSO, or missing permissions). '
            . 'Run `gh auth login` or set a GitHub App in Settings.';
    }

    public static function looksStale(string $message): bool
    {
        $m = strtolower($message);
        $needles = [
            '401',
            '403',
            'bad credentials',
            'token expired',
            'token is expired',
            'sso',
            'must be authorized',
            'requires authentication',
            'resource not accessible',
            'missing the',
            'insufficient',
            'refresh the token',
            'http 401',
            'http 403',
            'gh_token',
        ];
        foreach ($needles as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }
        return false;
    }
}
