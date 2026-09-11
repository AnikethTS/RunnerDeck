<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$csrfToken = Csrf::token();
$hasLogo = is_file(__DIR__ . '/assets/logo.png');
$needsSetup = !Config::isConfigured();

try {
    $currentScope = Config::scope();
} catch (RuntimeException) {
    $currentScope = 'org';
}
$currentSettings = [
    'scope' => $currentScope,
    'org' => (string) getenv('RUNNERDECK_ORG'),
    'repo' => (string) getenv('RUNNERDECK_REPO'),
    'label' => Config::label(),
];

$snapshot = $needsSetup ? null : Dashboard::snapshot(5);
$accountLabel = $needsSetup ? null : ($currentScope === 'repo' ? $currentSettings['repo'] : $currentSettings['org']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>RunnerDeck</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>" />
</head>
<body>
  <header class="topbar">
    <?php if ($hasLogo) : ?>
      <img src="assets/logo.png" alt="Logo" class="logo" />
    <?php endif; ?>
    <h1>RunnerDeck</h1>
    <?php if (!$needsSetup) : ?>
      <span class="org-tag">
        <?= htmlspecialchars((string) $accountLabel) ?> &middot; label: <?= htmlspecialchars(Config::label()) ?>
      </span>
      <button id="btn-settings" class="btn btn-sm">Settings</button>
    <?php endif; ?>
  </header>

  <?php if ($needsSetup) : ?>
    <main class="setup-main">
      <div class="setup-card">
        <h2>Set up RunnerDeck</h2>
        <p class="muted">
          Choose how runners should be managed. GitHub has no
          personal-account-level runner — pick an org you admin, or a
          single repo you own.
        </p>
        <form id="setup-form" class="settings-form">
          <label for="setup-scope">Scope</label>
          <select id="setup-scope" name="scope">
            <option value="org">Organization</option>
            <option value="repo">Single repo</option>
          </select>

          <div id="setup-org-field" class="settings-field">
            <label for="setup-org">GitHub org</label>
            <input type="text" id="setup-org" name="org" placeholder="my-org" />
          </div>
          <div id="setup-repo-field" class="settings-field" hidden>
            <label for="setup-repo">Repo (owner/repo)</label>
            <input type="text" id="setup-repo" name="repo" placeholder="owner/repo" />
          </div>

          <label for="setup-label">Runner label (optional)</label>
          <input type="text" id="setup-label" name="label" placeholder="self-hosted-runnerdeck" />

          <button type="submit" class="btn btn-good">Save &amp; continue</button>
          <p id="setup-error" class="setup-error" hidden></p>
        </form>
      </div>
    </main>
  <?php else : ?>
    <div id="health-banner" class="banner" hidden></div>

    <main>
      <div class="toolbar">
        <button id="btn-refresh" class="btn">Refresh</button>
        <span id="last-updated" class="muted"></span>
        <span class="spacer"></span>
        <button id="btn-add-runner" class="btn">+ Add Runner</button>
        <button id="btn-start-all" class="btn btn-good">Start All</button>
        <button id="btn-stop-all" class="btn btn-critical">Stop All</button>
        <form id="pool-size-form" class="pool-form">
          <label for="pool-size">Pool size</label>
          <input type="number" id="pool-size" name="count" min="1" max="30" value="10" />
          <button type="submit" class="btn">Apply</button>
        </form>
      </div>

      <table class="runner-table">
        <thead>
          <tr>
            <th>Runner</th>
            <th>GitHub</th>
            <th>Local process</th>
            <th>Recent log</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="runner-rows"></tbody>
      </table>
    </main>

    <div id="log-modal" class="modal" hidden>
      <div class="modal-content">
        <div class="modal-header">
          <h2 id="log-modal-title">Log</h2>
          <button id="log-modal-close" class="btn">Close</button>
        </div>
        <pre id="log-modal-body" class="log-view"></pre>
      </div>
    </div>

    <div id="confirm-modal" class="modal" hidden>
      <div class="modal-content modal-content-small">
        <p id="confirm-modal-message"></p>
        <div class="modal-actions">
          <button id="confirm-modal-cancel" class="btn">Cancel</button>
          <button id="confirm-modal-ok" class="btn btn-critical">Stop anyway</button>
        </div>
      </div>
    </div>

    <div id="settings-modal" class="modal" hidden>
      <div class="modal-content modal-content-small">
        <div class="modal-header">
          <h2>Settings</h2>
          <button id="settings-modal-close" class="btn">Close</button>
        </div>
        <form id="settings-form" class="settings-form">
          <label for="settings-scope">Scope</label>
          <select id="settings-scope" name="scope">
            <option value="org">Organization</option>
            <option value="repo">Single repo</option>
          </select>

          <div id="settings-org-field" class="settings-field">
            <label for="settings-org">GitHub org</label>
            <input type="text" id="settings-org" name="org" placeholder="my-org" />
          </div>
          <div id="settings-repo-field" class="settings-field" hidden>
            <label for="settings-repo">Repo (owner/repo)</label>
            <input type="text" id="settings-repo" name="repo" placeholder="owner/repo" />
          </div>

          <label for="settings-label">Runner label (optional)</label>
          <input type="text" id="settings-label" name="label" placeholder="self-hosted-runnerdeck" />

          <div class="modal-actions">
            <button type="button" id="settings-cancel" class="btn">Cancel</button>
            <button type="submit" class="btn btn-good">Save</button>
          </div>
          <p id="settings-error" class="setup-error" hidden></p>
        </form>
      </div>
    </div>

    <div id="add-runner-modal" class="modal" hidden>
      <div class="modal-content modal-content-small">
        <div class="modal-header">
          <h2>Add runner</h2>
          <button id="add-runner-modal-close" class="btn">Close</button>
        </div>
        <form id="add-runner-form" class="settings-form">
          <label for="add-runner-name">Name (optional)</label>
          <input type="text" id="add-runner-name" name="name" placeholder="leave blank to auto-name" />
          <div class="modal-actions">
            <button type="button" id="add-runner-cancel" class="btn">Cancel</button>
            <button type="submit" class="btn btn-good">Add</button>
          </div>
          <p id="add-runner-error" class="setup-error" hidden></p>
        </form>
      </div>
    </div>

    <div id="rename-modal" class="modal" hidden>
      <div class="modal-content modal-content-small">
        <div class="modal-header">
          <h2>Rename runner</h2>
          <button id="rename-modal-close" class="btn">Close</button>
        </div>
        <p class="muted">
          Renaming stops the runner, deregisters it from GitHub under its old
          name, then re-registers and starts it under the new name.
        </p>
        <form id="rename-form" class="settings-form">
          <input type="hidden" id="rename-runner-id" name="runner" />
          <label for="rename-name">New name</label>
          <input type="text" id="rename-name" name="name" required />
          <div class="modal-actions">
            <button type="button" id="rename-cancel" class="btn">Cancel</button>
            <button type="submit" class="btn btn-good">Rename</button>
          </div>
          <p id="rename-error" class="setup-error" hidden></p>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <footer class="footer">
    <a href="https://github.com/AnikethTS/RunnerDeck"
       target="_blank" rel="noopener noreferrer">RunnerDeck on GitHub</a>
    <span class="footer-sep">&middot;</span>
    <a href="https://github.com/AnikethTS/RunnerDeck/blob/main/LICENSE"
       target="_blank" rel="noopener noreferrer">MIT License</a>
  </footer>

  <script>
    window.__SNAPSHOT__ = <?= json_encode($snapshot) ?>;
    window.__CSRF__ = <?= json_encode($csrfToken) ?>;
    window.__NEEDS_SETUP__ = <?= json_encode($needsSetup) ?>;
    window.__CURRENT_SETTINGS__ = <?= json_encode($currentSettings) ?>;
  </script>
  <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>" defer></script>
</body>
</html>
