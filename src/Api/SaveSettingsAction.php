<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\AppLog;
use RunnerDeck\Config;
use RunnerDeck\CrashWebhook;
use RunnerDeck\GithubApp;
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
        $extraLabelsRaw = trim((string) ($post['extra_labels'] ?? ''));
        $webhookUrl = trim((string) ($post['crash_webhook_url'] ?? ''));
        $webhookThreshold = trim((string) ($post['crash_webhook_threshold'] ?? ''));
        $drainTimeout = trim((string) ($post['drain_timeout'] ?? ''));
        $diskThreshold = trim((string) ($post['disk_webhook_threshold'] ?? ''));
        $sessionIdle = trim((string) ($post['session_idle_minutes'] ?? ''));
        $poolCap = trim((string) ($post['pool_cap'] ?? ''));
        $idleStop = trim((string) ($post['idle_stop_minutes'] ?? ''));
        $queueWait = trim((string) ($post['queue_wait_minutes'] ?? ''));
        $longJob = trim((string) ($post['long_job_minutes'] ?? ''));
        $minDisk = trim((string) ($post['min_free_disk_gib'] ?? ''));
        $minRam = trim((string) ($post['min_free_ram_mib'] ?? ''));
        $slotCpu = trim((string) ($post['slot_cpu_percent'] ?? ''));
        $slotMem = trim((string) ($post['slot_memory_max_mib'] ?? ''));
        $appId = trim((string) ($post['github_app_id'] ?? ''));
        $appInstall = trim((string) ($post['github_app_installation_id'] ?? ''));
        $appPem = trim((string) ($post['github_app_private_key'] ?? ''));

        if ($scope === 'org' && $org === '') {
            return ['ok' => false, 'message' => 'org is required for org scope'];
        }
        if ($scope === 'repo' && !str_contains($repo, '/')) {
            return ['ok' => false, 'message' => "repo must be in 'owner/repo' format"];
        }
        $extraLabels = Config::parseLabels($extraLabelsRaw);
        if ($extraLabels === null) {
            return [
                'ok' => false,
                'message' => 'extra labels must be up to 12 comma-separated names '
                    . '(letters, digits, dot, underscore, hyphen)',
            ];
        }
        if ($webhookUrl !== '' && !CrashWebhook::isValidUrl($webhookUrl)) {
            return ['ok' => false, 'message' => 'crash webhook url must start with http:// or https://'];
        }
        if ($webhookThreshold !== '' && (!ctype_digit($webhookThreshold) || (int) $webhookThreshold < 1)) {
            return ['ok' => false, 'message' => 'crash webhook threshold must be a whole number of 1 or more'];
        }
        $drainOk = $drainTimeout === ''
            || (ctype_digit($drainTimeout) && (int) $drainTimeout >= 1 && (int) $drainTimeout <= 3600);
        if (!$drainOk) {
            return ['ok' => false, 'message' => 'drain timeout must be a whole number between 1 and 3600 seconds'];
        }
        if ($diskThreshold !== '' && (!ctype_digit($diskThreshold) || (int) $diskThreshold < 1)) {
            return ['ok' => false, 'message' => 'disk webhook threshold must be a whole number of 1 or more GiB'];
        }
        $idleOk = $sessionIdle === ''
            || (ctype_digit($sessionIdle) && (int) $sessionIdle >= 1 && (int) $sessionIdle <= 1440);
        if (!$idleOk) {
            return [
                'ok' => false,
                'message' => 'session idle timeout must be a whole number between 1 and 1440 minutes',
            ];
        }
        $capOk = $poolCap === ''
            || (ctype_digit($poolCap) && (int) $poolCap >= 1 && (int) $poolCap <= 30);
        if (!$capOk) {
            return ['ok' => false, 'message' => 'pool cap must be a whole number between 1 and 30'];
        }
        $idleStopOk = $idleStop === ''
            || (ctype_digit($idleStop) && (int) $idleStop >= 1 && (int) $idleStop <= 1440);
        if (!$idleStopOk) {
            return [
                'ok' => false,
                'message' => 'idle stop must be a whole number between 1 and 1440 minutes',
            ];
        }
        $queueWaitOk = $queueWait === ''
            || (ctype_digit($queueWait) && (int) $queueWait >= 1 && (int) $queueWait <= 1440);
        if (!$queueWaitOk) {
            return [
                'ok' => false,
                'message' => 'queue wait alert must be a whole number between 1 and 1440 minutes',
            ];
        }
        $longJobOk = $longJob === ''
            || (ctype_digit($longJob) && (int) $longJob >= 1 && (int) $longJob <= 1440);
        if (!$longJobOk) {
            return [
                'ok' => false,
                'message' => 'long job alert must be a whole number between 1 and 1440 minutes',
            ];
        }
        $diskOk = $minDisk === ''
            || (ctype_digit($minDisk) && (int) $minDisk <= 1000);
        if (!$diskOk) {
            return [
                'ok' => false,
                'message' => 'minimum free disk must be a whole number of GiB from 0 to 1000 (0 = off)',
            ];
        }
        $appIdOk = $appId === '' || (ctype_digit($appId) && (int) $appId > 0);
        if (!$appIdOk) {
            return ['ok' => false, 'message' => 'GitHub App ID must be a whole number'];
        }
        $appInstallOk = $appInstall === ''
            || (ctype_digit($appInstall) && (int) $appInstall > 0);
        if (!$appInstallOk) {
            return ['ok' => false, 'message' => 'GitHub App installation ID must be a whole number'];
        }
        if ($appPem !== '' && (!str_contains($appPem, 'BEGIN') || !str_contains($appPem, 'PRIVATE KEY'))) {
            return ['ok' => false, 'message' => 'GitHub App private key must be a PEM private key'];
        }

        $cpuOk = $slotCpu === ''
            || (ctype_digit($slotCpu) && (int) $slotCpu >= 1 && (int) $slotCpu <= 100);
        if (!$cpuOk) {
            return ['ok' => false, 'message' => 'slot CPU cap must be a whole number between 1 and 100'];
        }
        $memOk = $slotMem === ''
            || (ctype_digit($slotMem) && (int) $slotMem >= 64 && (int) $slotMem <= 1048576);
        if (!$memOk) {
            return [
                'ok' => false,
                'message' => 'slot memory cap must be a whole number of MiB from 64 to 1048576',
            ];
        }

        $ramOk = $minRam === ''
            || (ctype_digit($minRam) && (int) $minRam <= 1048576);
        if (!$ramOk) {
            return [
                'ok' => false,
                'message' => 'minimum free RAM must be a whole number of MiB from 0 to 1048576 (0 = off)',
            ];
        }

        if ($appPem !== '' && !GithubApp::savePrivateKey($appPem)) {
            return ['ok' => false, 'message' => 'could not save GitHub App private key'];
        }

        Settings::save([
            'RUNNERDECK_SCOPE' => $scope,
            'RUNNERDECK_ORG' => $scope === 'org' ? $org : '',
            'RUNNERDECK_REPO' => $scope === 'repo' ? $repo : '',
            'RUNNERDECK_LABEL' => $label,
            'RUNNERDECK_EXTRA_LABELS' => implode(',', $extraLabels),
            'RUNNERDECK_CHECK_UPDATES' => ($post['check_updates'] ?? '') === '1' ? '1' : '',
            'RUNNERDECK_AUTH_TOTP_SECRET' => Config::authTotpSecret() ?? '',
            'RUNNERDECK_AUTO_RESTART' => ($post['auto_restart'] ?? '') === '1' ? '1' : '',
            'RUNNERDECK_SCALE_TO_QUEUE' => ($post['scale_to_queue'] ?? '') === '1' ? '1' : '',
            'RUNNERDECK_POOL_CAP' => $poolCap,
            'RUNNERDECK_IDLE_STOP_MINUTES' => $idleStop,
            'RUNNERDECK_AUTO_CLEAR_WORK' => ($post['auto_clear_work'] ?? '') === '1' ? '1' : '',
            'RUNNERDECK_QUEUE_WAIT_MINUTES' => $queueWait,
            'RUNNERDECK_LONG_JOB_MINUTES' => $longJob,
            'RUNNERDECK_MIN_FREE_DISK_GIB' => $minDisk,
            'RUNNERDECK_MIN_FREE_RAM_MIB' => $minRam,
            'RUNNERDECK_CRASH_WEBHOOK_URL' => $webhookUrl,
            'RUNNERDECK_CRASH_WEBHOOK_THRESHOLD' => $webhookThreshold,
            'RUNNERDECK_DRAIN_TIMEOUT' => $drainTimeout,
            'RUNNERDECK_DISK_WEBHOOK_THRESHOLD' => $diskThreshold,
            'RUNNERDECK_SESSION_IDLE_MINUTES' => $sessionIdle,
            'RUNNERDECK_SYSLOG' => ($post['syslog'] ?? '') === '1' ? '1' : '',
            'RUNNERDECK_SLOT_CPU_PERCENT' => $slotCpu,
            'RUNNERDECK_SLOT_MEMORY_MAX_MIB' => $slotMem,
            'RUNNERDECK_GITHUB_APP_ID' => $appId,
            'RUNNERDECK_GITHUB_APP_INSTALLATION_ID' => $appInstall,
        ]);

        $bits = ['scope=' . $scope];
        if ($scope === 'org') {
            $bits[] = 'org=' . $org;
        } else {
            $bits[] = 'repo=' . $repo;
        }
        $bits[] = 'auto_restart=' . (($post['auto_restart'] ?? '') === '1' ? '1' : '0');
        $bits[] = 'scale_to_queue=' . (($post['scale_to_queue'] ?? '') === '1' ? '1' : '0');
        $bits[] = 'webhook=' . ($webhookUrl !== '' ? 'set' : 'off');
        $bits[] = 'github_app=' . ($appId !== '' && $appInstall !== '' ? 'set' : 'off');
        $bits[] = 'syslog=' . (($post['syslog'] ?? '') === '1' ? '1' : '0');
        AppLog::info('settings.save', 'settings saved (' . implode(' ', $bits) . ')');

        return ['ok' => true, 'message' => 'settings saved'];
    }
}
