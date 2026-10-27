<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Core\Auth;
use RunnerDeck\Core\Csrf;
use RunnerDeck\Core\Layout;
use RunnerDeck\Host\History;

Auth::requirePageAccess();

$csrfToken = Csrf::token();
$unavailable = !History::isAvailable();
$samples = $unavailable ? [] : History::downsample(History::recent(History::DAY_SECONDS));
$cpu = [];
$mem = [];
$disk = [];
foreach ($samples as $row) {
    $cpu[] = (float) $row['avg_cpu'];
    $mem[] = ((int) $row['total_rss_kb']) / 1024.0;
    if (is_numeric($row['disk_free_kb'] ?? null)) {
        $disk[] = ((int) $row['disk_free_kb']) / 1048576.0;
    }
}
$memMax = $mem === [] ? 10.0 : (float) max(10.0, ...$mem);
$diskMax = $disk === [] ? 1.0 : (float) max(1.0, ...$disk);
$cpuSum = History::summary($cpu, '%', 1, '24h');
$memSum = History::summary($mem, ' MB', 0, '24h');
$diskSum = History::summary($disk, ' GiB', 1, '24h');

Layout::htmlOpen('RunnerDeck — History');
Layout::topbarStart();
Layout::topbarEndStart();
Layout::pageNav('history.php');
Layout::logoutForm($csrfToken);
Layout::topbarEnd();
?>
  <main class="ops-main">
    <header class="settings-header">
      <h2>Host history</h2>
      <p class="muted">
        CPU, runner RSS, and pool disk free from dashboard polls. No extra GitHub calls.
        Last 24 hours. Dashboard sparklines still show the last hour.
      </p>
    </header>
    <div class="history-grid ops-history-grid">
      <div class="history-card">
        <div class="history-head">
          <span class="stat-label">CPU % &middot; 24h</span>
          <span class="history-now"><?= htmlspecialchars($cpuSum['now']) ?></span>
        </div>
        <span class="stat-sub"><?= htmlspecialchars($cpuSum['span']) ?></span>
        <svg class="history-chart" viewBox="0 0 400 110" role="img" aria-label="CPU percent 24 hours">
          <?= History::polylineSvg($cpu, 0.0, 100.0, $unavailable, '%', 0, '-24h') ?>
        </svg>
      </div>
      <div class="history-card">
        <div class="history-head">
          <span class="stat-label">Memory MB &middot; 24h</span>
          <span class="history-now"><?= htmlspecialchars($memSum['now']) ?></span>
        </div>
        <span class="stat-sub"><?= htmlspecialchars($memSum['span']) ?></span>
        <svg class="history-chart" viewBox="0 0 400 110" role="img" aria-label="Memory MB 24 hours">
          <?= History::polylineSvg($mem, 0.0, $memMax, $unavailable, ' MB', 0, '-24h') ?>
        </svg>
      </div>
      <div class="history-card">
        <div class="history-head">
          <span class="stat-label">Disk free GiB &middot; 24h</span>
          <span class="history-now"><?= htmlspecialchars($diskSum['now']) ?></span>
        </div>
        <span class="stat-sub"><?= htmlspecialchars($diskSum['span']) ?></span>
        <svg class="history-chart" viewBox="0 0 400 110" role="img" aria-label="Disk free GiB 24 hours">
          <?= History::polylineSvg($disk, 0.0, $diskMax, $unavailable, ' GiB', 1, '-24h') ?>
        </svg>
      </div>
    </div>
  </main>
<?php
Layout::htmlClose(['assets/js/theme.js']);
