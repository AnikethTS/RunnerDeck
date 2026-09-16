<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Config;
use RunnerDeck\Settings;

final class SaveSettingsAction
{
    public static function handle(): never
    {
        $scope = $_POST['scope'] ?? '';
        if ($scope !== 'org' && $scope !== 'repo') {
            JsonApi::respond(['ok' => false, 'message' => "scope must be 'org' or 'repo'"], 422);
        }

        $org = trim((string) ($_POST['org'] ?? ''));
        $repo = trim((string) ($_POST['repo'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));

        if ($scope === 'org' && $org === '') {
            JsonApi::respond(['ok' => false, 'message' => 'org is required for org scope'], 422);
        }
        if ($scope === 'repo' && !str_contains($repo, '/')) {
            JsonApi::respond(['ok' => false, 'message' => "repo must be in 'owner/repo' format"], 422);
        }

        Settings::save([
            'RUNNERDECK_SCOPE' => $scope,
            'RUNNERDECK_ORG' => $scope === 'org' ? $org : '',
            'RUNNERDECK_REPO' => $scope === 'repo' ? $repo : '',
            'RUNNERDECK_LABEL' => $label,
            'RUNNERDECK_CHECK_UPDATES' => ($_POST['check_updates'] ?? '') === '1' ? '1' : '',
            'RUNNERDECK_AUTH_TOTP_SECRET' => Config::authTotpSecret() ?? '',
            'RUNNERDECK_AUTO_RESTART' => ($_POST['auto_restart'] ?? '') === '1' ? '1' : '',
        ]);

        JsonApi::respond(['ok' => true, 'message' => 'settings saved']);
    }
}
