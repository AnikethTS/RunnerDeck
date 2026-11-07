<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Configuration\Config;
use RunnerDeck\Presentation\Dashboard;
use RunnerDeck\Presentation\DashboardView;
use RunnerDeck\Presentation\Layout;
use RunnerDeck\Security\Auth;
use RunnerDeck\Security\Csrf;
use RunnerDeck\Security\SecurityHeaders;

Auth::requirePageAccess();

if (!Config::isConfigured()) {
    header('Location: index.php');
    exit;
}

$id = (string) ($_GET['id'] ?? '');
$runner = null;
if (preg_match('/^runner-(base|[0-9]+)$/', $id) === 1) {
    $snapshot = Dashboard::snapshot(200);
    foreach ($snapshot['runners'] as $row) {
        if (is_array($row) && ($row['id'] ?? '') === $id) {
            $runner = $row;
            break;
        }
    }
}

$csrfToken = Csrf::token();
$title = $runner === null ? 'RunnerDeck — Runner' : 'RunnerDeck — ' . $id;
Layout::htmlOpen($title);
Layout::topbarStart();
Layout::pageNav('');
Layout::topbarEndStart();
Layout::logoutForm($csrfToken);
Layout::topbarEnd();

if ($runner === null) {
    $safe = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    echo '<main class="ops-main runner-page">' . "\n";
    echo '  <p class="runner-back"><a href="index.php">Runners</a></p>' . "\n";
    echo '  <h2>Runner not on this host</h2>' . "\n";
    echo '  <p class="muted">' . ($safe === '' ? 'No runner was selected.' : $safe . ' is not a slot on this host.')
        . "</p>\n";
    echo "</main>\n";
    Layout::htmlClose(['assets/js/theme.js']);
    return;
}

echo DashboardView::runnerPage($runner);
?>
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
<?php
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
echo '  <script' . SecurityHeaders::nonceAttr() . ">\n";
echo '    window.__CSRF__ = ' . json_encode($csrfToken, $jsonFlags) . ";\n";
echo '    window.__DRAIN_TIMEOUT__ = ' . json_encode(Config::drainTimeoutSeconds(), $jsonFlags) . ";\n";
echo "  </script>\n";
Layout::htmlClose(['assets/runner.js']);
