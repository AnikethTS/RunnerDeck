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
        'extraLabels' => (string) ($_POST['extra_labels'] ?? ''),
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
        'extraLabels' => implode(',', Config::extraLabels()),
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
Layout::logoutForm($csrfToken);
Layout::topbarEnd();
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
        <p class="settings-hint">Optional webhook for crash-loop, crash count, and disk.</p>

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
