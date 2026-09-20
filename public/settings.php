<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Api\SaveSettingsAction;
use RunnerDeck\Auth;
use RunnerDeck\Config;
use RunnerDeck\Csrf;

if (Auth::isEnabled() && !Auth::isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verifyRequest()) {
        $error = 'Session expired — reload and try again.';
    } else {
        $result = SaveSettingsAction::save($_POST);
        if ($result['ok']) {
            header('Location: index.php');
            exit;
        }
        $error = $result['message'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = [
        'scope' => (string) ($_POST['scope'] ?? 'org'),
        'org' => (string) ($_POST['org'] ?? ''),
        'repo' => (string) ($_POST['repo'] ?? ''),
        'label' => (string) ($_POST['label'] ?? ''),
        'checkUpdates' => ($_POST['check_updates'] ?? '') === '1',
        'autoRestart' => ($_POST['auto_restart'] ?? '') === '1',
        'crashWebhookUrl' => (string) ($_POST['crash_webhook_url'] ?? ''),
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
    ];
}

$hasLogo = is_file(__DIR__ . '/assets/logo.png');
$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>RunnerDeck — Settings</title>
  <?php if ($hasLogo) : ?>
    <link rel="icon" href="assets/logo.png" />
  <?php endif; ?>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>" />
</head>
<body>
  <header class="topbar">
    <?php if ($hasLogo) : ?>
      <img src="assets/logo.png" alt="Logo" class="logo" />
    <?php endif; ?>
    <h1>RunnerDeck</h1>
  </header>

  <main class="settings-main">
    <h2>Settings</h2>
    <p class="muted"><a href="index.php">&larr; Back to dashboard</a></p>

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
      </section>

      <section class="settings-section">
        <h3>Notifications</h3>

        <div class="settings-field">
          <label for="settings-crash-webhook-url">Crash-loop webhook URL (optional)</label>
          <input
            type="url"
            id="settings-crash-webhook-url"
            name="crash_webhook_url"
            placeholder="https://hooks.slack.com/services/..."
            value="<?= htmlspecialchars($current['crashWebhookUrl']) ?>"
          />
          <p class="muted">
            Slack and Discord incoming webhook URLs are detected automatically;
            anything else gets a plain JSON payload.
          </p>
        </div>
      </section>

      <section class="settings-section">
        <h3>Login</h3>
        <p class="muted">
          <?= Auth::isEnabled()
            ? 'Enabled — an authenticator app code is required to sign in.'
            : 'Disabled — anyone who can reach this port has full access.' ?>
        </p>
        <a href="totp_setup.php" class="btn btn-sm">
          <?= Auth::isEnabled() ? 'Replace secret' : 'Set up login' ?>
        </a>
      </section>

      <button type="submit" class="btn btn-good">Save</button>
      <?php if ($error !== null) : ?>
        <p class="setup-error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>
    </form>
  </main>

  <script>
    (() => {
      const scopeSelect = document.getElementById('settings-scope');
      const orgField = document.getElementById('settings-org-field');
      const repoField = document.getElementById('settings-repo-field');
      const update = () => {
        const isRepo = scopeSelect.value === 'repo';
        orgField.hidden = isRepo;
        repoField.hidden = !isRepo;
      };
      scopeSelect.addEventListener('change', update);
      update();
    })();
  </script>
</body>
</html>
