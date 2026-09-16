<?php

declare(strict_types=1);

final class SaveSettingsAction
{
    public static function handle(): never
    {
        $scope = $_POST['scope'] ?? '';
        if ($scope !== 'org' && $scope !== 'repo') {
            Api::respond(['ok' => false, 'message' => "scope must be 'org' or 'repo'"], 422);
        }

        $org = trim((string) ($_POST['org'] ?? ''));
        $repo = trim((string) ($_POST['repo'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));

        if ($scope === 'org' && $org === '') {
            Api::respond(['ok' => false, 'message' => 'org is required for org scope'], 422);
        }
        if ($scope === 'repo' && !str_contains($repo, '/')) {
            Api::respond(['ok' => false, 'message' => "repo must be in 'owner/repo' format"], 422);
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

        Api::respond(['ok' => true, 'message' => 'settings saved']);
    }
}

final class LogoutAction
{
    public static function handle(): never
    {
        if (Auth::isEnabled()) {
            Auth::logout();
        }
        Api::respond(['ok' => true]);
    }
}

final class TotpBeginAction
{
    public static function handle(): never
    {
        $secret = Auth::beginTotpSetup();
        $uri = 'otpauth://totp/RunnerDeck?secret=' . $secret . '&issuer=RunnerDeck';
        Api::respond(['ok' => true, 'secret' => $secret, 'uri' => $uri]);
    }
}

final class TotpConfirmAction
{
    public static function handle(): never
    {
        if (!Auth::confirmTotpSetup((string) ($_POST['code'] ?? ''))) {
            Api::respond(['ok' => false, 'message' => 'Invalid code'], 422);
        }
        Api::respond(['ok' => true]);
    }
}
