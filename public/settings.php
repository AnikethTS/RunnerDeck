<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Api\SaveSettingsAction;
use RunnerDeck\Auth;
use RunnerDeck\Config;
use RunnerDeck\Csrf;
use RunnerDeck\Layout;

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
) {
    $current = [
        'scope' => (string) ($_POST['scope'] ?? 'org'),
        'org' => (string) ($_POST['org'] ?? ''),
        'repo' => (string) ($_POST['repo'] ?? ''),
        'label' => (string) ($_POST['label'] ?? ''),
        'checkUpdates' => ($_POST['check_updates'] ?? '') === '1',
        'autoRestart' => ($_POST['auto_restart'] ?? '') === '1',
        'crashWebhookUrl' => (string) ($_POST['crash_webhook_url'] ?? ''),
        'crashWebhookThreshold' => (string) ($_POST['crash_webhook_threshold'] ?? ''),
        'drainTimeout' => (string) ($_POST['drain_timeout'] ?? ''),
        'diskWebhookThreshold' => (string) ($_POST['disk_webhook_threshold'] ?? ''),
        'sessionIdleMinutes' => (string) ($_POST['session_idle_minutes'] ?? ''),
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
        'checkUpdates' => Config::checkUpdatesEnabled(),
        'autoRestart' => Config::autoRestartEnabled(),
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
    ];
}

$csrfToken = Csrf::token();

Layout::htmlOpen('RunnerDeck — Settings');
Layout::topbarStart();
Layout::topbarEndStart();
Layout::topbarEnd();
?>

  <main class="settings-main">
    <h2>Settings</h2>
    <p class="muted"><a href="index.php">&larr; Back to dashboard</a></p>
    <?php if ($notice !== null) : ?>
      <p class="muted"><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>
    <?php if ($recoveryCodes !== []) : ?>
      <section class="settings-section">
        <h3>New recovery codes</h3>
        <p class="muted">Store these offline. Each works once. They are not shown again.</p>
        <ul>
          <?php foreach ($recoveryCodes as $code) : ?>
            <li><code><?= htmlspecialchars($code) ?></code></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <form method="post" class="settings-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />

      <section class="settings-section">
        <h3>Repository</h3>
        <p class="muted">Which runners this instance manages.</p>

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
      </section>

      <section class="settings-section">
        <h3>Automation</h3>

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

        <div class="settings-field">
          <label for="settings-drain-timeout">Drain timeout (seconds)</label>
          <input
            type="number"
            id="settings-drain-timeout"
            name="drain_timeout"
            min="1"
            max="3600"
            step="1"
            value="<?= htmlspecialchars($current['drainTimeout']) ?>"
          />
          <p class="muted">
            How long Drain waits for GitHub <code>busy</code> to clear before
            asking to stop anyway. Default 600 (10 minutes). Range 1–3600.
          </p>
        </div>
      </section>

      <section class="settings-section">
        <h3>Notifications</h3>

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
          <p class="muted">
            Slack and Discord incoming webhook URLs are detected automatically;
            anything else gets a plain JSON payload. Testing sends whatever's
            typed above — it doesn't need to be saved first.
          </p>
        </div>

        <div class="settings-field">
          <label for="settings-crash-webhook-threshold">Also notify at N crashes in 7 days (optional)</label>
          <input
            type="number"
            id="settings-crash-webhook-threshold"
            name="crash_webhook_threshold"
            min="1"
            step="1"
            placeholder="leave blank for crash-loop only"
            value="<?= htmlspecialchars($current['crashWebhookThreshold']) ?>"
          />
          <p class="muted">
            Uses the same URL. Fires once when a runner's 7-day crash count
            first reaches this number — even if auto-restart keeps it up
            so a crash-loop is never flagged.
          </p>
        </div>

        <div class="settings-field">
          <label for="settings-disk-webhook-threshold">Also notify at N GiB disk (optional)</label>
          <input
            type="number"
            id="settings-disk-webhook-threshold"
            name="disk_webhook_threshold"
            min="1"
            step="1"
            placeholder="leave blank to skip"
            value="<?= htmlspecialchars($current['diskWebhookThreshold']) ?>"
          />
          <p class="muted">
            Uses the same URL. Fires once when a slot, or the pool total,
            first reaches this many GiB of <code>_work</code> / logs.
          </p>
        </div>
      </section>

      <section class="settings-section">
        <h3>Login</h3>
        <p class="muted">
          <?= Auth::isEnabled()
            ? 'Enabled — an authenticator app code is required to sign in.'
            : 'Off on loopback. Requests that are not from 127.0.0.1/::1 are refused until you set up login.' ?>
        </p>
        <a href="totp_setup.php" class="btn btn-sm">
          <?= Auth::isEnabled() ? 'Replace secret' : 'Set up login' ?>
        </a>
        <?php if (Auth::isEnabled()) : ?>
          <p class="muted">
            <?= Auth::recoveryCodesRemaining() ?> recovery code(s) left.
            A stolen session cookie can still start and stop runners until
            it expires — set an idle timeout or sign out other sessions.
          </p>
          <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
            <button type="submit" name="regenerate_recovery" value="1" class="btn btn-sm">
              Regenerate recovery codes
            </button>
          </form>
          <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
            <button type="submit" name="revoke_sessions" value="1" class="btn btn-sm">
              Sign out other sessions
            </button>
          </form>
        <?php endif; ?>

        <div class="settings-field">
          <label for="settings-session-idle">Idle timeout (minutes, optional)</label>
          <input
            type="number"
            id="settings-session-idle"
            name="session_idle_minutes"
            min="1"
            max="1440"
            step="1"
            placeholder="leave blank to keep the browser session"
            value="<?= htmlspecialchars($current['sessionIdleMinutes']) ?>"
          />
          <p class="muted">
            When login is on, sign out after this many minutes with no
            requests. Hidden dashboard tabs do not poll, so they count as
            idle. Range 1–1440. Blank keeps the cookie until the browser
            closes.
          </p>
        </div>
      </section>

      <button type="submit" class="btn btn-good">Save</button>
      <?php if ($error !== null) : ?>
        <p class="setup-error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>
    </form>
  </main>
<?php
Layout::htmlClose(['assets/js/theme.js', 'assets/js/scope-toggle.js', 'assets/js/webhook-test.js']);
