<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Auth;
use RunnerDeck\Config;
use RunnerDeck\Csrf;
use RunnerDeck\Dashboard;

if (Auth::isEnabled() && !Auth::isLoggedIn()) {
    header('Location: login.php');
    exit;
}

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
    'checkUpdates' => Config::checkUpdatesEnabled(),
    'autoRestart' => Config::autoRestartEnabled(),
    'crashWebhookUrl' => Config::crashWebhookUrl() ?? '',
];

$snapshot = $needsSetup ? null : Dashboard::snapshot(5);
$accountLabel = $needsSetup ? null : ($currentScope === 'repo' ? $currentSettings['repo'] : $currentSettings['org']);
$themeCookie = $_COOKIE['runnerdeck-theme'] ?? '';
$themeAttr = ($themeCookie === 'dark' || $themeCookie === 'light')
    ? ' data-theme="' . htmlspecialchars($themeCookie, ENT_QUOTES) . '"'
    : '';
$history = is_array($snapshot) ? ($snapshot['history'] ?? null) : null;
?>
<!doctype html>
<html lang="en"<?= $themeAttr ?>>
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>RunnerDeck</title>
  <link rel="manifest" href="assets/manifest.webmanifest" />
  <?php if ($hasLogo) : ?>
    <link rel="icon" href="assets/logo.png" />
  <?php endif; ?>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>" />
</head>
<body>
  <div id="toast-container"></div>
  <header class="topbar">
    <?php if ($hasLogo) : ?>
      <img src="assets/logo.png" alt="Logo" class="logo" />
    <?php endif; ?>
    <h1>RunnerDeck</h1>
    <span class="edition-tag">Community</span>
    <span class="version-tag">v<?= htmlspecialchars(Config::version()) ?></span>
    <a id="update-available" class="update-badge" href="https://github.com/AnikethTS/RunnerDeck/releases/latest"
       target="_blank" rel="noopener noreferrer" hidden></a>
    <?php if (!$needsSetup) : ?>
      <span class="org-tag">
        <?= htmlspecialchars((string) $accountLabel) ?> &middot; label: <?= htmlspecialchars(Config::label()) ?>
      </span>
      <button id="btn-settings" class="btn btn-sm">Settings</button>
        <?php if (Auth::isEnabled()) : ?>
        <button id="btn-logout" class="btn btn-sm">Log out</button>
        <?php endif; ?>
    <?php endif; ?>
    <button id="btn-theme-toggle" class="btn btn-sm" aria-label="Toggle theme" title="Toggle theme">
    <svg
    id="theme-icon-sun"
    width="16"
    height="16"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <circle cx="12" cy="12" r="5"></circle>
    <line x1="12" y1="1" x2="12" y2="3"></line>
    <line x1="12" y1="21" x2="12" y2="23"></line>
    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
    <line x1="1" y1="12" x2="3" y2="12"></line>
    <line x1="21" y1="12" x2="23" y2="12"></line>
    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
  </svg>
   <svg
    id="theme-icon-moon"
    width="16"
    height="16"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    hidden
  >
    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
  </svg>
</button>
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
      <div class="stat-grid">
        <div class="stat-card">
          <span class="stat-label">Active Runners</span>
          <span class="stat-value" id="stat-active">—</span>
          <span class="stat-sub" id="stat-active-sub">—</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">GitHub API</span>
          <span class="stat-value" id="stat-github">—</span>
          <span class="stat-sub" id="stat-github-sub">—</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Avg CPU</span>
          <span class="stat-value" id="stat-cpu">—</span>
          <span class="stat-sub" id="stat-cpu-sub">—</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Total Memory</span>
          <span class="stat-value" id="stat-mem">—</span>
          <span class="stat-sub" id="stat-mem-sub">—</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">System CPU</span>
          <span class="stat-value" id="stat-sys-cpu">—</span>
          <span class="stat-sub" id="stat-sys-cpu-sub">—</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">System RAM</span>
          <span class="stat-value" id="stat-sys-mem">—</span>
          <span class="stat-sub" id="stat-sys-mem-sub">—</span>
        </div>
      </div>

      <div class="history-grid">
        <div class="history-card">
          <span class="stat-label">CPU % &middot; last hour</span>
          <svg id="chart-cpu" class="history-chart" viewBox="0 0 300 60" preserveAspectRatio="none">
            <?= is_array($history) ? $history['cpu_svg'] : '' ?>
          </svg>
        </div>
        <div class="history-card">
          <span class="stat-label">Memory MB &middot; last hour</span>
          <svg id="chart-mem" class="history-chart" viewBox="0 0 300 60" preserveAspectRatio="none">
            <?= is_array($history) ? $history['mem_svg'] : '' ?>
          </svg>
        </div>
      </div>

      <div class="toolbar">
        <button id="btn-refresh" class="btn">Refresh</button>
        <button id="btn-export" type="button" class="btn" disabled>Export JSON</button>
        <span id="last-updated" class="muted"></span>
        <input type="search" id="runner-filter" class="filter-input" placeholder="Filter runners…" />
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

      <div id="bulk-actions-bar" class="bulk-actions-bar" hidden>
        <span id="bulk-actions-count" class="muted"></span>
        <span class="spacer"></span>
        <button id="btn-bulk-start" class="btn btn-sm btn-good">Start</button>
        <button id="btn-bulk-stop" class="btn btn-sm btn-critical">Stop</button>
        <button id="btn-bulk-delete" class="btn btn-sm btn-critical">Delete</button>
        <button id="btn-bulk-clear" class="btn btn-sm">Clear</button>
      </div>

      <table class="runner-table">
        <thead>
          <tr>
            <th class="select-col"><input type="checkbox" id="select-all-runners" /></th>
            <th class="sortable" data-sort="id">Runner<span class="sort-caret"></span></th>
            <th class="sortable" data-sort="status">GitHub<span class="sort-caret"></span></th>
            <th>Local process
              <div class="process-sort-controls">
                <button type="button" class="sortable sort-button" data-sort="cpu" aria-label="Sort by CPU">
                  CPU<span class="sort-caret" aria-hidden="true"></span>
                </button>
                <button type="button" class="sortable sort-button" data-sort="uptime" aria-label="Sort by uptime">
                  Uptime<span class="sort-caret" aria-hidden="true"></span>
                </button>
              </div>
            </th>
            <th>Recent log</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="runner-rows"></tbody>
      </table>
    </main>

    <div id="log-modal" class="modal" hidden>
      <div class="modal-content">
        <div class="modal-header log-modal-header">
          <h2 id="log-modal-title">Log</h2>
          <div class="modal-header-actions">
            <input type="search" id="log-search" class="filter-input"
                   aria-label="Search log" placeholder="Search log…" />
            <a id="log-modal-download" class="btn btn-sm" href="#" download>Download</a>
            <button id="log-modal-close" class="btn">Close</button>
          </div>
        </div>
        <div class="log-range-row">
          <label for="log-range-from">Download from</label>
          <input type="datetime-local" id="log-range-from" />
          <label for="log-range-to">to</label>
          <input type="datetime-local" id="log-range-to" />
          <span class="muted">(leave blank for the full log)</span>
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

          <label class="checkbox-label">
            <input type="checkbox" id="settings-check-updates" name="check_updates" value="1" />
            Check GitHub for new RunnerDeck releases
          </label>

          <label class="checkbox-label">
            <input type="checkbox" id="settings-auto-restart" name="auto_restart" value="1" />
            Auto-restart crashed runners
          </label>

          <div class="settings-field">
            <label for="settings-crash-webhook-url">Crash-loop webhook URL (optional)</label>
            <input
              type="url"
              id="settings-crash-webhook-url"
              name="crash_webhook_url"
              placeholder="https://hooks.slack.com/services/..."
            />
            <p class="muted">
              Slack and Discord incoming webhook URLs are detected automatically;
              anything else gets a plain JSON payload.
            </p>
          </div>

          <div class="settings-field">
            <label>Login</label>
            <p class="muted">
              <?= Auth::isEnabled()
                ? 'Enabled — an authenticator app code is required to sign in.'
                : 'Disabled — anyone who can reach this port has full access.' ?>
            </p>
            <a href="totp_setup.php" class="btn btn-sm">
              <?= Auth::isEnabled() ? 'Replace secret' : 'Set up login' ?>
            </a>
          </div>

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
<script type="module" src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
