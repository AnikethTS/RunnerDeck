<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Core\Auth;
use RunnerDeck\Core\Csrf;
use RunnerDeck\Core\Layout;
use RunnerDeck\Host\History;
use RunnerDeck\Pool\CrashHistory;

Auth::requirePageAccess();

$csrfToken = Csrf::token();
$days = CrashHistory::countsByUtcDay(7);
$bars = [];
foreach ($days as $row) {
    $bars[] = ['label' => $row['day'], 'count' => $row['count']];
}
$events = CrashHistory::exportEvents(100);

Layout::htmlOpen('RunnerDeck — Crashes');
Layout::topbarStart();
Layout::topbarEndStart();
Layout::pageNav('crashes.php');
Layout::logoutForm($csrfToken);
Layout::topbarEnd();
?>
  <main class="ops-main">
    <header class="settings-header">
      <h2>Crash timeline</h2>
      <p class="muted">
        Seven UTC days from the local crash log. No GitHub. Empty when sqlite is missing.
      </p>
    </header>
    <div class="history-card">
      <div class="history-head">
        <span class="stat-label">Crashes per UTC day</span>
      </div>
      <svg class="history-chart" viewBox="0 0 400 110" role="img" aria-label="Crashes per day">
        <?= History::barSvg($bars) ?>
      </svg>
    </div>
    <?php if ($events === []) : ?>
      <p class="muted">No crash events in the last 7 days.</p>
    <?php else : ?>
      <div class="table-scroll">
      <table class="ops-table">
        <thead>
          <tr><th>When (UTC)</th><th>Slot</th><th>Agent</th></tr>
        </thead>
        <tbody>
          <?php foreach ($events as $row) : ?>
            <tr>
              <td><?= htmlspecialchars(gmdate('Y-m-d H:i:s', $row['ts'])) ?></td>
              <td><?= htmlspecialchars($row['runner_id']) ?></td>
              <td><?= htmlspecialchars($row['agent_name']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </main>
<?php
Layout::htmlClose(['assets/js/theme.js']);
