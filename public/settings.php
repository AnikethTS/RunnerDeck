<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Api\SaveSettingsAction;
use RunnerDeck\Auth;
use RunnerDeck\Config;
use RunnerDeck\Csrf;
use RunnerDeck\HostingWarnings;
use RunnerDeck\Layout;
use RunnerDeck\GithubApp;
use RunnerDeck\HostBundle;
use RunnerDeck\SettingsPortable;

Auth::requirePageAccess();

$error = null;
$notice = null;
$recoveryCodes = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verifyRequest()) {
        $error = 'Session expired — reload and try again.';
    } elseif (isset($_POST['regenerate_recovery'])) {
        if (!Auth::isEnabled()) {
            $error = 'Set up login before creating recovery codes.';
        } else {
            Auth::issueRecoveryCodes();
            $recoveryCodes = Auth::takeIssuedRecoveryCodes();
        }
    } elseif (isset($_POST['revoke_sessions'])) {
        if (!Auth::isEnabled()) {
            $error = 'Login is not enabled.';
        } else {
            Auth::revokeOtherSessions();
            $notice = 'Other sessions are signed out. This one stays open.';
        }
    } elseif (isset($_POST['import_settings'])) {
        $file = $_FILES['settings_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Choose a settings JSON file or host bundle to import.';
        } elseif ((int) ($file['size'] ?? 0) > HostBundle::MAX_BYTES) {
            $error = 'import file is too large.';
        } else {
            $raw = (string) file_get_contents((string) ($file['tmp_name'] ?? ''));
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $error = 'import file is not JSON.';
            } elseif (HostBundle::isBundle($decoded)) {
                $result = HostBundle::import($decoded);
                if ($result['ok']) {
                    header('Location: settings.php');
                    exit;
                }
                $error = $result['message'];
            } elseif ((int) ($file['size'] ?? 0) > 65536) {
                $error = 'settings file is too large.';
            } else {
                $result = SettingsPortable::import($decoded);
                if ($result['ok']) {
                    header('Location: settings.php');
                    exit;
                }
                $error = $result['message'];
            }
        }
    } else {
        $result = SaveSettingsAction::save($_POST);
        if ($result['ok']) {
            header('Location: index.php');
            exit;
        }
        $error = $result['message'];
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['regenerate_recovery'])
    && !isset($_POST['revoke_sessions'])
    && !isset($_POST['import_settings'])
) {
    $current = [
        'scope' => (string) ($_POST['scope'] ?? 'org'),
        'org' => (string) ($_POST['org'] ?? ''),
        'repo' => (string) ($_POST['repo'] ?? ''),
        'label' => (string) ($_POST['label'] ?? ''),
        'extraLabels' => (string) ($_POST['extra_labels'] ?? ''),
        'checkUpdates' => ($_POST['check_updates'] ?? '') === '1',
        'autoRestart' => ($_POST['auto_restart'] ?? '') === '1',
        'scaleToQueue' => ($_POST['scale_to_queue'] ?? '') === '1',
        'poolCap' => (string) ($_POST['pool_cap'] ?? ''),
        'idleStopMinutes' => (string) ($_POST['idle_stop_minutes'] ?? ''),
        'autoClearWork' => ($_POST['auto_clear_work'] ?? '') === '1',
        'queueWaitMinutes' => (string) ($_POST['queue_wait_minutes'] ?? ''),
        'longJobMinutes' => (string) ($_POST['long_job_minutes'] ?? ''),
        'minFreeDiskGib' => (string) ($_POST['min_free_disk_gib'] ?? ''),
        'minFreeRamMib' => (string) ($_POST['min_free_ram_mib'] ?? ''),
        'crashWebhookUrl' => (string) ($_POST['crash_webhook_url'] ?? ''),
        'crashWebhookThreshold' => (string) ($_POST['crash_webhook_threshold'] ?? ''),
        'drainTimeout' => (string) ($_POST['drain_timeout'] ?? ''),
        'diskWebhookThreshold' => (string) ($_POST['disk_webhook_threshold'] ?? ''),
        'sessionIdleMinutes' => (string) ($_POST['session_idle_minutes'] ?? ''),
        'syslog' => ($_POST['syslog'] ?? '') === '1',
        'slotCpuPercent' => (string) ($_POST['slot_cpu_percent'] ?? ''),
        'slotMemoryMaxMib' => (string) ($_POST['slot_memory_max_mib'] ?? ''),
        'githubAppId' => (string) ($_POST['github_app_id'] ?? ''),
        'githubAppInstallationId' => (string) ($_POST['github_app_installation_id'] ?? ''),
        'githubAppKeyPresent' => GithubApp::hasPrivateKey(),
    ];
} else {
    try {
        $scope = Config::scope();
    } catch (RuntimeException) {
        $scope = 'org';
    }
    $current = [
        'scope' => $scope,
        'org' => (string) getenv('RUNNERDECK_ORG'),
        'repo' => (string) getenv('RUNNERDECK_REPO'),
        'label' => Config::label(),
        'extraLabels' => implode(',', Config::extraLabels()),
        'checkUpdates' => Config::checkUpdatesEnabled(),
        'autoRestart' => Config::autoRestartEnabled(),
        'scaleToQueue' => Config::scaleToQueueEnabled(),
        'poolCap' => Config::poolCap() !== null ? (string) Config::poolCap() : '',
        'idleStopMinutes' => Config::idleStopMinutes() !== null
            ? (string) Config::idleStopMinutes()
            : '',
        'autoClearWork' => Config::autoClearWork(),
        'queueWaitMinutes' => Config::queueWaitMinutes() !== null
            ? (string) Config::queueWaitMinutes()
            : '',
        'longJobMinutes' => Config::longJobMinutes() !== null
            ? (string) Config::longJobMinutes()
            : '',
        'minFreeDiskGib' => (string) Config::minFreeDiskGib(),
        'minFreeRamMib' => (string) Config::minFreeRamMib(),
        'crashWebhookUrl' => Config::crashWebhookUrl() ?? '',
        'crashWebhookThreshold' => Config::crashWebhookThreshold() !== null
            ? (string) Config::crashWebhookThreshold()
            : '',
        'drainTimeout' => (string) Config::drainTimeoutSeconds(),
        'diskWebhookThreshold' => Config::diskWebhookThresholdGb() !== null
            ? (string) Config::diskWebhookThresholdGb()
            : '',
        'sessionIdleMinutes' => Config::sessionIdleMinutes() !== null
            ? (string) Config::sessionIdleMinutes()
            : '',
        'syslog' => Config::syslogEnabled(),
        'slotCpuPercent' => Config::slotCpuPercent() !== null
            ? (string) Config::slotCpuPercent()
            : '',
        'slotMemoryMaxMib' => Config::slotMemoryMaxMib() !== null
            ? (string) Config::slotMemoryMaxMib()
            : '',
        'githubAppId' => GithubApp::appId() !== null ? (string) GithubApp::appId() : '',
        'githubAppInstallationId' => GithubApp::installationId() !== null
            ? (string) GithubApp::installationId()
            : '',
        'githubAppKeyPresent' => GithubApp::hasPrivateKey(),
    ];
}

$csrfToken = Csrf::token();

Layout::htmlOpen('RunnerDeck — Settings');
Layout::topbarStart();
Layout::topbarEndStart();
Layout::logoutForm($csrfToken);
Layout::topbarEnd();
echo HostingWarnings::html();
?>

  <main class="settings-main">
    <header class="settings-header">
      <a href="index.php" class="settings-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="15 18 9 12 15 6"></polyline>
        </svg>
        Dashboard
      </a>
      <h2>Settings</h2>
      <p class="muted">This host only — org, automation, alerts, and login.</p>
    </header>
    <?php if ($notice !== null) : ?>
      <p class="settings-flash" role="status"><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>
    <?php if ($recoveryCodes !== []) : ?>
      <section class="settings-card settings-callout" aria-labelledby="recovery-codes-heading">
        <h3 id="recovery-codes-heading">New recovery codes</h3>
        <p class="settings-hint">Store these offline. Each works once. They are not shown again.</p>
        <ul class="settings-code-list">
          <?php foreach ($recoveryCodes as $code) : ?>
            <li><code><?= htmlspecialchars($code) ?></code></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?= HostingWarnings::settingsCardHtml(); ?>

    <form method="post" class="settings-form" id="settings-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />

      <section class="settings-card">
        <h3>Repository</h3>
        <p class="settings-hint">Which GitHub org or repo this pool registers against.</p>

        <label for="settings-scope">Scope</label>
        <select id="settings-scope" name="scope">
          <option value="org" <?= $current['scope'] === 'org' ? 'selected' : '' ?>>Organization</option>
          <option value="repo" <?= $current['scope'] === 'repo' ? 'selected' : '' ?>>Single repo</option>
        </select>

        <div id="settings-org-field" class="settings-field">
          <label for="settings-org">GitHub org</label>
          <input
            type="text"
            id="settings-org"
            name="org"
            placeholder="my-org"
            value="<?= htmlspecialchars($current['org']) ?>"
          />
        </div>
        <div id="settings-repo-field" class="settings-field">
          <label for="settings-repo">Repo (owner/repo)</label>
          <input
            type="text"
            id="settings-repo"
            name="repo"
            placeholder="owner/repo"
            value="<?= htmlspecialchars($current['repo']) ?>"
          />
        </div>

        <label for="settings-label">Runner label (optional)</label>
        <input
          type="text"
          id="settings-label"
          name="label"
          placeholder="self-hosted-runnerdeck"
          value="<?= htmlspecialchars($current['label']) ?>"
        />

        <label for="settings-extra-labels">Extra labels (optional)</label>
        <input
          type="text"
          id="settings-extra-labels"
          name="extra_labels"
          placeholder="linux,gpu"
          value="<?= htmlspecialchars($current['extraLabels']) ?>"
        />
        <p class="settings-hint">
          Extra labels apply on add and rename. Existing slots keep theirs until then.
        </p>
      </section>

      <section class="settings-card">
        <h3>GitHub App</h3>
        <p class="settings-hint">
          Optional org-owned app instead of <code>gh auth login</code>.
          Needs App ID, installation ID, and a PEM private key (stored in
          <code>storage/github_app.pem</code>, never in settings JSON).
        </p>
        <div class="settings-field">
          <label for="settings-github-app-id">App ID (optional)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-github-app-id"
            name="github_app_id"
            min="1"
            step="1"
            value="<?= htmlspecialchars($current['githubAppId']) ?>"
            placeholder="off"
          />
        </div>
        <div class="settings-field">
          <label for="settings-github-app-install">Installation ID (optional)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-github-app-install"
            name="github_app_installation_id"
            min="1"
            step="1"
            value="<?= htmlspecialchars($current['githubAppInstallationId']) ?>"
            placeholder="off"
          />
        </div>
        <div class="settings-field">
          <label for="settings-github-app-pem">Private key PEM</label>
          <textarea
            id="settings-github-app-pem"
            name="github_app_private_key"
            rows="6"
            placeholder="<?= $current['githubAppKeyPresent']
                ? 'Key is saved. Paste a new PEM to replace it.'
                : '-----BEGIN PRIVATE KEY-----' ?>"
          ></textarea>
          <p class="settings-hint">
            Leave blank to keep the saved key. Permissions: Administration
            (org or repo runners) read/write.
          </p>
        </div>
      </section>

      <section class="settings-card">
        <h3>Automation</h3>
        <p class="settings-hint">What happens when a runner is idle, busy, or dies.</p>

        <label class="checkbox-label">
          <input
            type="checkbox"
            name="check_updates"
            value="1"
            <?= $current['checkUpdates'] ? 'checked' : '' ?>
          />
          Check GitHub for new RunnerDeck releases
        </label>

        <label class="checkbox-label">
          <input
            type="checkbox"
            name="auto_restart"
            value="1"
            <?= $current['autoRestart'] ? 'checked' : '' ?>
          />
          Auto-restart crashed runners
        </label>

        <label class="checkbox-label">
          <input
            type="checkbox"
            name="scale_to_queue"
            value="1"
            <?= $current['scaleToQueue'] ? 'checked' : '' ?>
          />
          Start stopped slots when jobs are waiting on this label
        </label>

        <div class="settings-field">
          <label for="settings-pool-cap">Pool cap (optional)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-pool-cap"
            name="pool_cap"
            min="1"
            max="30"
            step="1"
            value="<?= htmlspecialchars($current['poolCap']) ?>"
            placeholder="no cap"
          />
          <p class="settings-hint">Never start more than this many slots (1–30). Leave blank for no cap.</p>
        </div>

        <div class="settings-field">
          <label for="settings-idle-stop">Idle stop after (minutes, optional)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-idle-stop"
            name="idle_stop_minutes"
            min="1"
            max="1440"
            step="1"
            value="<?= htmlspecialchars($current['idleStopMinutes']) ?>"
            placeholder="off"
          />
          <p class="settings-hint">
            Stop a slot that has been GitHub-idle this long. Off while jobs are queued.
            Needs watch-auto-restart.
          </p>
        </div>

        <label class="checkbox-label">
          <input
            type="checkbox"
            name="auto_clear_work"
            value="1"
            <?= $current['autoClearWork'] ? 'checked' : '' ?>
          />
          Delete <code>_work</code> after idle-stop or drain-stop (not after a regular Stop)
        </label>

        <div class="settings-field">
          <label for="settings-queue-wait">Queue wait alert (minutes, optional)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-queue-wait"
            name="queue_wait_minutes"
            min="1"
            max="1440"
            step="1"
            value="<?= htmlspecialchars($current['queueWaitMinutes']) ?>"
            placeholder="off"
          />
          <p class="settings-hint">
            Fire the crash webhook once when a job waits this long on this label.
          </p>
        </div>

        <div class="settings-field">
          <label for="settings-long-job">Long job alert (minutes, optional)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-long-job"
            name="long_job_minutes"
            min="1"
            max="1440"
            step="1"
            value="<?= htmlspecialchars($current['longJobMinutes']) ?>"
            placeholder="off"
          />
          <p class="settings-hint">
            Fire the crash webhook once when an in-progress job on this host has run this long.
          </p>
        </div>

        <div class="settings-field">
          <label for="settings-min-disk">Minimum free disk (GiB)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-min-disk"
            name="min_free_disk_gib"
            min="0"
            max="1000"
            step="1"
            value="<?= htmlspecialchars($current['minFreeDiskGib']) ?>"
          />
          <p class="settings-hint">
            Refuse starts when the pool filesystem has less than this free. 0 = off. Default 1.
          </p>
        </div>

        <div class="settings-field">
          <label for="settings-min-ram">Minimum free RAM (MiB)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-min-ram"
            name="min_free_ram_mib"
            min="0"
            max="1048576"
            step="1"
            value="<?= htmlspecialchars($current['minFreeRamMib']) ?>"
          />
          <p class="settings-hint">
            Refuse starts when Linux MemAvailable is below this. 0 = off. Default 256.
            Ignored when RAM cannot be read (macOS).
          </p>
        </div>

        <div class="settings-field">
          <label for="settings-slot-cpu">Slot CPU cap (percent of one CPU)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-slot-cpu"
            name="slot_cpu_percent"
            min="1"
            max="100"
            step="1"
            value="<?= htmlspecialchars($current['slotCpuPercent']) ?>"
            placeholder="off"
          />
          <p class="settings-hint">
            Linux cgroup v2 <code>cpu.max</code> when a slot starts. Off if blank.
            macOS and missing cgroup permissions fail open (the runner still starts).
          </p>
        </div>

        <div class="settings-field">
          <label for="settings-slot-mem">Slot memory cap (MiB)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-slot-mem"
            name="slot_memory_max_mib"
            min="64"
            max="1048576"
            step="1"
            value="<?= htmlspecialchars($current['slotMemoryMaxMib']) ?>"
            placeholder="off"
          />
          <p class="settings-hint">cgroup v2 <code>memory.max</code> on start. 64–1048576. Blank = off.</p>
        </div>

        <div class="settings-field">
          <label for="settings-drain-timeout">Drain timeout (seconds)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-drain-timeout"
            name="drain_timeout"
            min="1"
            max="3600"
            step="1"
            value="<?= htmlspecialchars($current['drainTimeout']) ?>"
          />
          <p class="settings-hint">Wait for GitHub <code>busy=false</code> before stop. 1–3600, default 600.</p>
        </div>
      </section>

      <section class="settings-card">
        <h3>Notifications</h3>
        <p class="settings-hint">Optional webhook for crash-loop, host mode, queue/long-job alerts, and disk.
          Failed posts retry with backoff. Prometheus is <code>GET /metrics.php</code> (no TOTP, no GitHub).
        </p>

        <label class="checkbox-label">
          <input
            type="checkbox"
            name="syslog"
            value="1"
            <?= $current['syslog'] ? 'checked' : '' ?>
          />
          Send crash and alert events to local syslog
        </label>
        <p class="settings-hint">
          RFC5424 datagram to <code>/dev/log</code> (or <code>RUNNERDECK_SYSLOG_SOCKET</code>).
          Fail-open if the socket is missing.
        </p>

        <div class="settings-field">
          <label for="settings-crash-webhook-url">Crash-loop webhook URL (optional)</label>
          <div class="settings-field-row">
            <input
              type="url"
              id="settings-crash-webhook-url"
              name="crash_webhook_url"
              placeholder="https://hooks.slack.com/services/..."
              value="<?= htmlspecialchars($current['crashWebhookUrl']) ?>"
            />
            <button type="button" id="settings-test-webhook" class="btn btn-sm">Test</button>
          </div>
          <p id="settings-test-webhook-result" class="muted" hidden></p>
          <p class="settings-hint">
            Slack and Discord URLs get a native payload; anything else gets JSON.
            Test sends the URL as typed — no need to save first.
          </p>
        </div>

        <div class="settings-field">
          <label for="settings-crash-webhook-threshold">Notify at N crashes in 7 days</label>
          <input
            type="number"
            class="settings-num"
            id="settings-crash-webhook-threshold"
            name="crash_webhook_threshold"
            min="1"
            step="1"
            placeholder="crash-loop only"
            value="<?= htmlspecialchars($current['crashWebhookThreshold']) ?>"
          />
          <p class="settings-hint">Same URL. Fires once when the 7-day count first reaches N.</p>
        </div>

        <div class="settings-field">
          <label for="settings-disk-webhook-threshold">Notify at N GiB disk</label>
          <input
            type="number"
            class="settings-num"
            id="settings-disk-webhook-threshold"
            name="disk_webhook_threshold"
            min="1"
            step="1"
            placeholder="skip"
            value="<?= htmlspecialchars($current['diskWebhookThreshold']) ?>"
          />
          <p class="settings-hint">Same URL. Fires once when a slot or the pool total first reaches N GiB.</p>
        </div>
      </section>

      <section class="settings-card">
        <h3>Login</h3>
        <p class="settings-hint">
          <?= Auth::isEnabled()
            ? 'On — an authenticator code is required to sign in. '
                . Auth::recoveryCodesRemaining() . ' recovery code(s) left.'
            : 'Off on loopback. Off-loopback requests are refused until login is set up.' ?>
        </p>
        <div class="settings-actions-row">
          <a href="totp_setup.php" class="btn btn-sm">
            <?= Auth::isEnabled() ? 'Replace secret' : 'Set up login' ?>
          </a>
        </div>

        <div class="settings-field">
          <label for="settings-session-idle">Idle timeout (minutes)</label>
          <input
            type="number"
            class="settings-num"
            id="settings-session-idle"
            name="session_idle_minutes"
            min="1"
            max="1440"
            step="1"
            placeholder="until the browser closes"
            value="<?= htmlspecialchars($current['sessionIdleMinutes']) ?>"
          />
          <p class="settings-hint">
            Sign out after this many minutes with no requests (1–1440). Hidden tabs count as idle.
          </p>
        </div>
      </section>

    </form>

    <section class="settings-card">
      <h3>This host copy</h3>
      <p class="settings-hint">
        Settings JSON omits the TOTP secret and crash webhook URL. A host
        bundle also copies slot flags, crash history, pause-AR, roster, and
        host mode. Neither overwrites login. A missing webhook keeps the one
        already saved unless the bundle was downloaded with secrets included.
        Restore does not copy runner binaries, <code>_work</code>, or
        <code>gh</code> credentials — Start re-registers empty slots.
      </p>
      <div class="settings-actions-row">
        <a href="download_settings.php" class="btn btn-sm" download="runnerdeck-settings.json">
          Download settings
        </a>
        <a href="download_bundle.php" class="btn btn-sm" download="runnerdeck-host-bundle.json">
          Download host bundle
        </a>
        <form method="post" action="download_bundle.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <label class="checkbox-label">
            <input type="checkbox" name="include_secrets" value="1" />
            Include crash webhook URL
          </label>
          <button type="submit" class="btn btn-sm">Download bundle with webhook</button>
        </form>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <input type="file" name="settings_file" accept="application/json,.json" required />
          <button type="submit" name="import_settings" value="1" class="btn btn-sm">
            Import
          </button>
        </form>
      </div>
    </section>

    <?php if (Auth::isEnabled()) : ?>
      <section class="settings-card">
        <h3>Session</h3>
        <p class="settings-hint">
          A stolen cookie can still start and stop runners until it expires.
        </p>
        <div class="settings-actions-row">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
            <button type="submit" name="regenerate_recovery" value="1" class="btn btn-sm">
              Regenerate recovery codes
            </button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
            <button type="submit" name="revoke_sessions" value="1" class="btn btn-sm">
              Sign out other sessions
            </button>
          </form>
        </div>
      </section>
    <?php endif; ?>

    <div class="settings-actions">
      <button type="submit" form="settings-form" id="settings-save" class="btn btn-good">Save</button>
      <?php if ($error !== null) : ?>
        <p class="setup-error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>
    </div>
  </main>
<?php
Layout::htmlClose(['assets/js/theme.js', 'assets/js/scope-toggle.js', 'assets/js/webhook-test.js']);
