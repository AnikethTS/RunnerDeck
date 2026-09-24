<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Api\SaveSettingsAction;
use RunnerDeck\Auth;
use RunnerDeck\Config;
use RunnerDeck\Csrf;
use RunnerDeck\Dashboard;
use RunnerDeck\DashboardView;
use RunnerDeck\Layout;
use RunnerDeck\SecurityHeaders;

if (Auth::isEnabled() && !Auth::isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$needsSetup = !Config::isConfigured();
$setupError = null;
$setupValues = [
    'scope' => 'org',
    'org' => '',
    'repo' => '',
    'label' => '',
];

if ($needsSetup && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verifyRequest()) {
        $setupError = 'Session expired — reload and try again.';
    } else {
        $result = SaveSettingsAction::save($_POST);
        if ($result['ok']) {
            header('Location: index.php');
            exit;
        }
        $setupError = $result['message'];
    }
    $setupValues = [
        'scope' => (string) ($_POST['scope'] ?? 'org'),
        'org' => (string) ($_POST['org'] ?? ''),
        'repo' => (string) ($_POST['repo'] ?? ''),
        'label' => (string) ($_POST['label'] ?? ''),
    ];
}

$csrfToken = Csrf::token();

try {
    $currentScope = Config::scope();
} catch (RuntimeException) {
    $currentScope = 'org';
}

$snapshot = $needsSetup ? null : Dashboard::snapshot(5);
$accountLabel = $needsSetup
    ? null
    : ($currentScope === 'repo' ? (string) getenv('RUNNERDECK_REPO') : (string) getenv('RUNNERDECK_ORG'));
$dash = is_array($snapshot) ? $snapshot : [];
$history = is_array($dash['history'] ?? null) ? $dash['history'] : [];
$stats = DashboardView::statsCards($dash);
$banner = DashboardView::healthBanner($dash);
$updated = DashboardView::lastUpdated($dash);
$rowsHtml = DashboardView::rowsHtml(is_array($dash['runners'] ?? null) ? $dash['runners'] : []);

Layout::htmlOpen('RunnerDeck', manifest: true);
?>
  <div id="toast-container"></div>
<?php
Layout::topbarStart();
?>
    <span class="edition-tag">Community</span>
    <span class="version-tag">v<?= htmlspecialchars(Config::version()) ?></span>
    <a id="update-available" class="update-badge" href="https://github.com/AnikethTS/RunnerDeck/releases/latest"
       target="_blank" rel="noopener noreferrer" hidden></a>
<?php
Layout::topbarEndStart();
?>
    <?php if (!$needsSetup) : ?>
      <span class="org-tag">
        <?= htmlspecialchars((string) $accountLabel) ?> &middot; label: <?= htmlspecialchars(Config::label()) ?>
      </span>
      <a href="settings.php" class="btn btn-sm">Settings</a>
        <?php if (Auth::isEnabled()) : ?>
        <button id="btn-logout" class="btn btn-sm">Log out</button>
        <?php endif; ?>
    <?php endif; ?>
<?php
Layout::topbarEnd();
?>

  <?php if ($needsSetup) : ?>
    <main class="setup-main">
      <div class="setup-card">
        <h2>Set up RunnerDeck</h2>
        <p class="muted">
          Choose how runners should be managed. GitHub has no
          personal-account-level runner — pick an org you admin, or a
          single repo you own.
        </p>
        <form id="setup-form" class="settings-form" method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <label for="setup-scope">Scope</label>
          <select id="setup-scope" name="scope">
            <option value="org" <?= $setupValues['scope'] === 'org' ? 'selected' : '' ?>>Organization</option>
            <option value="repo" <?= $setupValues['scope'] === 'repo' ? 'selected' : '' ?>>Single repo</option>
          </select>

          <div id="setup-org-field" class="settings-field">
            <label for="setup-org">GitHub org</label>
            <input type="text" id="setup-org" name="org" placeholder="my-org"
                   value="<?= htmlspecialchars($setupValues['org']) ?>" />
          </div>
          <div id="setup-repo-field" class="settings-field">
            <label for="setup-repo">Repo (owner/repo)</label>
            <input type="text" id="setup-repo" name="repo" placeholder="owner/repo"
                   value="<?= htmlspecialchars($setupValues['repo']) ?>" />
          </div>

          <label for="setup-label">Runner label (optional)</label>
          <input type="text" id="setup-label" name="label" placeholder="self-hosted-runnerdeck"
                 value="<?= htmlspecialchars($setupValues['label']) ?>" />

          <button type="submit" class="btn btn-good">Save &amp; continue</button>
          <?php if ($setupError !== null) : ?>
            <p id="setup-error" class="setup-error"><?= htmlspecialchars($setupError) ?></p>
          <?php else : ?>
            <p id="setup-error" class="setup-error" hidden></p>
          <?php endif; ?>
        </form>
      </div>
    </main>
  <?php else : ?>
    <div id="health-banner" class="banner"<?= $banner['hidden'] ? ' hidden' : '' ?>>
      <?= htmlspecialchars($banner['text']) ?>
    </div>

    <main>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="stat-label">Active Runners</span>
          <span class="stat-value" id="stat-active"><?= htmlspecialchars((string) $stats['active']) ?></span>
          <span class="stat-sub" id="stat-active-sub"><?= htmlspecialchars((string) $stats['active_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">GitHub API</span>
          <span
            class="<?= htmlspecialchars((string) $stats['github_class']) ?>"
            id="stat-github"
          ><?= htmlspecialchars((string) $stats['github']) ?></span>
          <span class="stat-sub" id="stat-github-sub"><?= htmlspecialchars((string) $stats['github_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Avg CPU</span>
          <span class="stat-value" id="stat-cpu"><?= htmlspecialchars((string) $stats['cpu']) ?></span>
          <span class="stat-sub" id="stat-cpu-sub"><?= htmlspecialchars((string) $stats['cpu_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Total Memory</span>
          <span class="stat-value" id="stat-mem"><?= htmlspecialchars((string) $stats['mem']) ?></span>
          <span class="stat-sub" id="stat-mem-sub"><?= htmlspecialchars((string) $stats['mem_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">System CPU</span>
          <span
            class="<?= htmlspecialchars((string) $stats['sys_cpu_class']) ?>"
            id="stat-sys-cpu"
          ><?= htmlspecialchars((string) $stats['sys_cpu']) ?></span>
          <span class="stat-sub" id="stat-sys-cpu-sub"><?= htmlspecialchars((string) $stats['sys_cpu_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">System RAM</span>
          <span
            class="<?= htmlspecialchars((string) $stats['sys_mem_class']) ?>"
            id="stat-sys-mem"
          ><?= htmlspecialchars((string) $stats['sys_mem']) ?></span>
          <span class="stat-sub" id="stat-sys-mem-sub"><?= htmlspecialchars((string) $stats['sys_mem_sub']) ?></span>
        </div>
      </div>

      <div class="history-grid">
        <div class="history-card">
          <span class="stat-label">CPU % &middot; last hour</span>
          <svg id="chart-cpu" class="history-chart" viewBox="0 0 300 60" preserveAspectRatio="none">
            <?= (string) ($history['cpu_svg'] ?? '') ?>
          </svg>
        </div>
        <div class="history-card">
          <span class="stat-label">Memory MB &middot; last hour</span>
          <svg id="chart-mem" class="history-chart" viewBox="0 0 300 60" preserveAspectRatio="none">
            <?= (string) ($history['mem_svg'] ?? '') ?>
          </svg>
        </div>
      </div>

      <div class="toolbar">
        <button id="btn-refresh" class="btn">Refresh</button>
        <button id="btn-export" type="button" class="btn">Export JSON</button>
        <span id="last-updated" class="muted"><?= htmlspecialchars($updated) ?></span>
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
                <button type="button" class="sortable sort-button" data-sort="crashes"
                        aria-label="Sort by crashes in 7 days">
                  Crashes<span class="sort-caret" aria-hidden="true"></span>
                </button>
              </div>
            </th>
            <th>Recent log</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="runner-rows"><?= $rowsHtml ?></tbody>
      </table>
      <?= DashboardView::errorsPanel(is_array($dash['errors'] ?? null) ? $dash['errors'] : []) ?>
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

    <div id="crash-history-modal" class="modal" hidden>
      <div class="modal-content modal-content-small">
        <div class="modal-header">
          <h2 id="crash-history-title">Crashes (7 days)</h2>
          <button type="button" id="crash-history-close" class="btn">Close</button>
        </div>
        <ol id="crash-history-list" class="crash-history-list"></ol>
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
<?php
if ($needsSetup) {
    Layout::htmlClose(['assets/js/theme.js', 'assets/js/scope-toggle.js']);
} else {
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    echo '  <script' . SecurityHeaders::nonceAttr() . ">\n";
    echo '    window.__SNAPSHOT__ = ' . json_encode($snapshot, $jsonFlags) . ";\n";
    echo '    window.__CSRF__ = ' . json_encode($csrfToken, $jsonFlags) . ";\n";
    echo "  </script>\n";
    Layout::htmlClose(['assets/app.js']);
}
