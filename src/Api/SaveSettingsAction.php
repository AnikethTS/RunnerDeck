<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Config;
use RunnerDeck\CrashWebhook;
use RunnerDeck\Settings;

final class SaveSettingsAction
{
    public static function handle(): never
    {
        $result = self::save($_POST);
        JsonApi::respond($result, $result['ok'] ? 200 : 422);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}
     */
    public static function save(array $post): array
    {
        $scope = $post['scope'] ?? '';
        if ($scope !== 'org' && $scope !== 'repo') {
            return ['ok' => false, 'message' => "scope must be 'org' or 'repo'"];
        }

        $org = trim((string) ($post['org'] ?? ''));
        $repo = trim((string) ($post['repo'] ?? ''));
        $label = trim((string) ($post['label'] ?? ''));
        $webhookUrl = trim((string) ($post['crash_webhook_url'] ?? ''));
        $webhookThreshold = trim((string) ($post['crash_webhook_threshold'] ?? ''));

        if ($scope === 'org' && $org === '') {
            return ['ok' => false, 'message' => 'org is required for org scope'];
        }
        if ($scope === 'repo' && !str_contains($repo, '/')) {
            return ['ok' => false, 'message' => "repo must be in 'owner/repo' format"];
        }
        if ($webhookUrl !== '' && !CrashWebhook::isValidUrl($webhookUrl)) {
            return ['ok' => false, 'message' => 'crash webhook url must start with http:// or https://'];
        }
        if ($webhookThreshold !== '' && (!ctype_digit($webhookThreshold) || (int) $webhookThreshold < 1)) {
            return ['ok' => false, 'message' => 'crash webhook threshold must be a whole number of 1 or more'];
        }

        Settings::save([
            'RUNNERDECK_SCOPE' => $scope,
            'RUNNERDECK_ORG' => $scope === 'org' ? $org : '',
            'RUNNERDECK_REPO' => $scope === 'repo' ? $repo : '',
            'RUNNERDECK_LABEL' => $label,
            'RUNNERDECK_CHECK_UPDATES' => ($post['check_updates'] ?? '') === '1' ? '1' : '',
            'RUNNERDECK_AUTH_TOTP_SECRET' => Config::authTotpSecret() ?? '',
            'RUNNERDECK_AUTO_RESTART' => ($post['auto_restart'] ?? '') === '1' ? '1' : '',
            'RUNNERDECK_CRASH_WEBHOOK_URL' => $webhookUrl,
            'RUNNERDECK_CRASH_WEBHOOK_THRESHOLD' => $webhookThreshold,
        ]);

        return ['ok' => true, 'message' => 'settings saved'];
    }
}
