<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Http\Action\SaveSettingsAction;
use RunnerDeck\Core\Auth;
use RunnerDeck\Core\Config;
use RunnerDeck\Core\Csrf;
use RunnerDeck\Ui\Dashboard;
use RunnerDeck\Ui\DashboardView;
use RunnerDeck\Core\HostingWarnings;
use RunnerDeck\Core\Layout;
use RunnerDeck\Core\SecurityHeaders;

Auth::requirePageAccess();

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

$snapshot = $needsSetup ? null : Dashboard::snapshot(5);
$dash = is_array($snapshot) ? $snapshot : [];
$history = is_array($dash['history'] ?? null) ? $dash['history'] : [];
$stats = DashboardView::statsCards($dash);
$tip = [
    'active' => Layout::infoIcon('How many slots on this host have a local runner process up.'),
    'github' => Layout::infoIcon(
        'Whether org/repo is reachable. Subtitle is remaining GitHub API quota when known.'
    ),
    'cpu' => Layout::infoIcon('Average CPU across running runner processes, not the whole machine.'),
    'mem' => Layout::infoIcon('RSS of running runner processes added together.'),
    'sysCpu' => Layout::infoIcon(
        'Whole-machine CPU. New starts are refused when this host is under pressure.'
    ),
    'sysRam' => Layout::infoIcon('Whole-machine RAM used. Same pressure check as System CPU.'),
    'poolDisk' => Layout::infoIcon('Disk used by runner slot directories on this host, including _work.'),
    'refresh' => Layout::hoverHelp('Reload pool status from this host and GitHub.'),
    'export' => Layout::hoverHelp('Download the current dashboard snapshot as JSON.'),
    'add' => Layout::hoverHelp('Create a new slot and install GitHub’s runner package on this host.'),
    'startAll' => Layout::hoverHelp('Start every stopped slot that is allowed to run.'),
    'stopAll' => Layout::hoverHelp(
        'SIGTERM every local runner process now. Jobs in progress are interrupted.'
    ),
    'drainAll' => Layout::hoverHelp(
        'Wait until each slot is idle on GitHub, then stop it. Busy slots keep the job.'
    ),
    'rollAll' => Layout::hoverHelp(
        'Drain and start slots one at a time so the pool is not all-offline at once.'
    ),
    'upgrade' => Layout::hoverHelp(
        'Replace each slot’s runner package with GitHub’s current download, one at a time.'
    ),
    'maint' => Layout::hoverHelp('Refuse new starts until you resume. Running slots keep going.'),
    'reboot' => Layout::hoverHelp(
        'Drain the pool, then mark this host safe to reboot. Does not reboot the OS.'
    ),
    'poolSize' => Layout::infoIcon(
        'How many runner slots this host should have (1–30). Apply adds or removes slots.'
    ),
    'apply' => Layout::hoverHelp('Add or remove slots so the pool matches this size.'),
    'bulkStart' => Layout::hoverHelp('Start the selected stopped slots.'),
    'bulkStop' => Layout::hoverHelp('Stop the selected slots now.'),
    'bulkDrain' => Layout::hoverHelp('Wait for GitHub idle, then stop the selected slots.'),
    'bulkRoll' => Layout::hoverHelp('Drain and start the selected slots one at a time.'),
    'bulkDelete' => Layout::hoverHelp('Deregister and remove the selected slots from this host.'),
    'bulkClear' => Layout::hoverHelp(
        'Clear _work on selected idle slots. Refuses while the process is running.'
    ),
];
$banner = DashboardView::healthBanner($dash);
$updated = DashboardView::lastUpdated($dash);
$rowsHtml = DashboardView::rowsHtml(is_array($dash['runners'] ?? null) ? $dash['runners'] : []);
if ($rowsHtml === '') {
    $rowsHtml = DashboardView::emptyRowsHtml('No runners in the pool yet.');
}

Layout::htmlOpen('RunnerDeck', manifest: true);
?>
  <div id="toast-container"></div>
<?php
Layout::topbarStart();
if (!$needsSetup) {
    Layout::pageNav('index.php');
}
Layout::topbarEndStart();
if (!$needsSetup) {
    Layout::logoutForm($csrfToken);
}
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
    <a class="skip-link" href="#runner-filter">Skip to runners</a>
    <div id="health-banner" class="banner"<?= $banner['hidden'] ? ' hidden' : '' ?>>
      <div class="page-shell"><?= htmlspecialchars($banner['text']) ?></div>
    </div>
      <?= HostingWarnings::html() ?>
    <noscript>
      <p class="banner banner-info">
        <span class="page-shell">
        Status on this page is from the last load. Refresh to update.
        Start, stop, and live logs need JavaScript.
        </span>
      </p>
    </noscript>

    <main>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="stat-label">Active Runners <?= $tip['active'] ?></span>
          <span class="stat-value" id="stat-active"><?= htmlspecialchars((string) $stats['active']) ?></span>
          <span class="stat-sub" id="stat-active-sub"><?= htmlspecialchars((string) $stats['active_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">GitHub API <?= $tip['github'] ?></span>
          <span
            class="<?= htmlspecialchars((string) $stats['github_class']) ?>"
            id="stat-github"
          ><?= htmlspecialchars((string) $stats['github']) ?></span>
          <span class="stat-sub" id="stat-github-sub"><?= htmlspecialchars((string) $stats['github_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Avg CPU <?= $tip['cpu'] ?></span>
          <span class="stat-value" id="stat-cpu"><?= htmlspecialchars((string) $stats['cpu']) ?></span>
          <span class="stat-sub" id="stat-cpu-sub"><?= htmlspecialchars((string) $stats['cpu_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Total Memory <?= $tip['mem'] ?></span>
          <span class="stat-value" id="stat-mem"><?= htmlspecialchars((string) $stats['mem']) ?></span>
          <span class="stat-sub" id="stat-mem-sub"><?= htmlspecialchars((string) $stats['mem_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">System CPU <?= $tip['sysCpu'] ?></span>
          <span
            class="<?= htmlspecialchars((string) $stats['sys_cpu_class']) ?>"
            id="stat-sys-cpu"
          ><?= htmlspecialchars((string) $stats['sys_cpu']) ?></span>
          <span class="stat-sub" id="stat-sys-cpu-sub"><?= htmlspecialchars((string) $stats['sys_cpu_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">System RAM <?= $tip['sysRam'] ?></span>
          <span
            class="<?= htmlspecialchars((string) $stats['sys_mem_class']) ?>"
            id="stat-sys-mem"
          ><?= htmlspecialchars((string) $stats['sys_mem']) ?></span>
          <span class="stat-sub" id="stat-sys-mem-sub"><?= htmlspecialchars((string) $stats['sys_mem_sub']) ?></span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Pool disk <?= $tip['poolDisk'] ?></span>
          <span class="stat-value" id="stat-pool-disk"><?= htmlspecialchars((string) $stats['pool_disk']) ?></span>
          <span
            class="stat-sub"
            id="stat-pool-disk-sub"
          ><?= htmlspecialchars((string) $stats['pool_disk_sub']) ?></span>
        </div>
      </div>

      <div class="history-grid">
        <div class="history-card">
          <div class="history-head">
            <span class="stat-label">CPU % &middot; last hour · <a href="history.php">24h</a></span>
            <span id="chart-cpu-now" class="history-now"><?=
                htmlspecialchars((string) ($history['cpu_now'] ?? '—'))
            ?></span>
          </div>
            <span id="chart-cpu-span" class="stat-sub"><?=
                htmlspecialchars((string) ($history['cpu_span'] ?? ''))
            ?></span>
          <svg
            id="chart-cpu"
            class="history-chart"
            viewBox="0 0 400 110"
            role="img"
            aria-label="CPU percent last hour"
          >
            <?= (string) ($history['cpu_svg'] ?? '') ?>
          </svg>
        </div>
        <div class="history-card">
          <div class="history-head">
            <span class="stat-label">Memory MB &middot; last hour</span>
            <span id="chart-mem-now" class="history-now"><?=
                htmlspecialchars((string) ($history['mem_now'] ?? '—'))
            ?></span>
          </div>
            <span id="chart-mem-span" class="stat-sub"><?=
                htmlspecialchars((string) ($history['mem_span'] ?? ''))
            ?></span>
          <svg
            id="chart-mem"
            class="history-chart"
            viewBox="0 0 400 110"
            role="img"
            aria-label="Memory MB last hour"
          >
            <?= (string) ($history['mem_svg'] ?? '') ?>
          </svg>
        </div>
      </div>

      <section id="queue-panel" class="queue-panel" hidden>
        <div class="history-head">
          <span class="stat-label">Queued jobs for this label</span>
          <span id="queue-count" class="history-now"></span>
        </div>
        <p id="queue-subtitle" class="stat-sub"></p>
        <p id="queue-hint" class="queue-hint" hidden></p>
        <button type="button" id="queue-hint-btn" class="btn btn-sm" hidden></button>
        <ul id="queue-jobs" class="queue-jobs"></ul>
        <p id="queue-error" class="muted" hidden></p>
      </section>

      <?php
        $orphanHtml = DashboardView::orphansHtml(
            is_array($dash['orphans'] ?? null) ? $dash['orphans'] : []
        );
        ?>
      <section id="orphan-panel" class="queue-panel"<?= $orphanHtml === '' ? ' hidden' : '' ?>>
        <?= $orphanHtml ?>
      </section>

      <div class="toolbar">
        <button id="btn-refresh" class="btn help-hover"<?= $tip['refresh'] ?>>Refresh</button>
        <button id="btn-export" type="button" class="btn help-hover"<?= $tip['export'] ?>>Export JSON</button>
        <span id="last-updated" class="muted"><?= htmlspecialchars($updated) ?></span>
        <input type="search" id="runner-filter" class="filter-input"
               placeholder="Filter by name, label, or version…" />
        <select id="runner-state-filter" class="filter-input filter-select" aria-label="Filter by state">
          <option value="">All states</option>
          <option value="idle">Idle</option>
          <option value="busy">Busy</option>
          <option value="crashed">Crashed</option>
          <option value="mismatch">Mismatch</option>
        </select>
        <span class="spacer"></span>
        <button id="btn-add-runner" class="btn help-hover"<?= $tip['add'] ?>>+ Add Runner</button>
        <button id="btn-start-all" class="btn btn-good help-hover"<?= $tip['startAll'] ?>>Start All</button>
        <button id="btn-stop-all" class="btn btn-critical help-hover"<?= $tip['stopAll'] ?>>Stop All</button>
        <button id="btn-drain-all" class="btn help-hover"<?= $tip['drainAll'] ?>>Drain All</button>
        <button id="btn-rolling-restart-all" class="btn help-hover"<?= $tip['rollAll'] ?>>Rolling Restart</button>
        <button id="btn-rolling-upgrade" class="btn help-hover"<?= $tip['upgrade'] ?>>Upgrade agents</button>
        <button id="btn-maintenance" class="btn help-hover"<?= $tip['maint'] ?>>Maintenance</button>
        <button id="btn-safe-reboot" class="btn help-hover"<?= $tip['reboot'] ?>>Safe reboot</button>
        <form id="pool-size-form" class="pool-form">
          <label for="pool-size">Pool size <?= $tip['poolSize'] ?></label>
          <input type="number" id="pool-size" name="count" min="1" max="30" value="10" />
          <button type="submit" class="btn help-hover"<?= $tip['apply'] ?>>Apply</button>
        </form>
      </div>

      <div id="bulk-actions-bar" class="bulk-actions-bar" hidden>
        <span id="bulk-actions-count" class="muted"></span>
        <span class="spacer"></span>
        <button id="btn-bulk-start" class="btn btn-sm btn-good help-hover"<?= $tip['bulkStart'] ?>>Start</button>
        <button id="btn-bulk-stop" class="btn btn-sm btn-critical help-hover"<?= $tip['bulkStop'] ?>>Stop</button>
        <button id="btn-bulk-drain" class="btn btn-sm help-hover"<?= $tip['bulkDrain'] ?>>Drain</button>
        <button id="btn-bulk-rolling-restart" class="btn btn-sm help-hover"<?= $tip['bulkRoll'] ?>>Roll restart</button>
        <button id="btn-bulk-delete" class="btn btn-sm btn-critical help-hover"<?= $tip['bulkDelete'] ?>>Delete</button>
        <button id="btn-bulk-clear" class="btn btn-sm help-hover"<?= $tip['bulkClear'] ?>>Clear</button>
      </div>

      <div class="runner-table-wrap">
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
                <button type="button" class="sortable sort-button" data-sort="disk"
                        aria-label="Sort by disk used">
                  Disk<span class="sort-caret" aria-hidden="true"></span>
                </button>
              </div>
            </th>
            <th>Recent log</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="runner-rows"><?= $rowsHtml ?></tbody>
      </table>
      </div>
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
          <label class="checkbox-label">
            <input type="checkbox" id="add-runner-ephemeral" value="1" />
            Ephemeral (one job, then re-register)
          </label>
          <label for="add-runner-labels">Extra labels (optional)</label>
          <input type="text" id="add-runner-labels" name="extra_labels" placeholder="gpu, linux" />
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
    <div class="page-shell">
    <a href="https://github.com/AnikethTS/RunnerDeck"
       target="_blank" rel="noopener noreferrer">RunnerDeck on GitHub</a>
    <span class="footer-sep">&middot;</span>
    <a href="https://github.com/AnikethTS/RunnerDeck/blob/main/LICENSE"
       target="_blank" rel="noopener noreferrer">MIT License</a>
    </div>
  </footer>
<?php
if ($needsSetup) {
    Layout::htmlClose(['assets/js/theme.js', 'assets/js/scope-toggle.js']);
} else {
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    echo '  <script' . SecurityHeaders::nonceAttr() . ">\n";
    echo '    window.__SNAPSHOT__ = ' . json_encode($snapshot, $jsonFlags) . ";\n";
    echo '    window.__CSRF__ = ' . json_encode($csrfToken, $jsonFlags) . ";\n";
    echo '    window.__DRAIN_TIMEOUT__ = ' . json_encode(Config::drainTimeoutSeconds(), $jsonFlags) . ";\n";
    echo "  </script>\n";
    Layout::htmlClose(['assets/app.js']);
}
