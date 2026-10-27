<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Core\Auth;
use RunnerDeck\Core\Config;
use RunnerDeck\Core\Csrf;
use RunnerDeck\Core\HostBundleSnapshot;
use RunnerDeck\Core\Layout;

Auth::requirePageAccess();

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::verifyRequest()) {
        $error = 'Session expired — reload and try again.';
    } else {
        $day = (string) ($_POST['delete_day'] ?? '');
        if (HostBundleSnapshot::delete($day)) {
            header('Location: bundles.php');
            exit;
        }
        $error = 'Could not delete that snapshot.';
    }
}

$csrfToken = Csrf::token();
$rows = HostBundleSnapshot::list();
$dlHelp = Layout::hoverHelp('Download this day’s JSON. TOTP is never in the file.');
$delHelp = Layout::hoverHelp('Delete this snapshot file. Does not change running settings.');

Layout::htmlOpen('RunnerDeck — Bundles');
Layout::topbarStart();
Layout::topbarEndStart();
Layout::pageNav('bundles.php');
Layout::logoutForm($csrfToken);
Layout::topbarEnd();
?>
  <main class="ops-main">
    <header class="settings-header">
      <h2>Host-bundle snapshots</h2>
      <p class="muted">
        Daily copies in <code>storage/bundles/</code>. TOTP is never in these files.
        Enable the setting under Automation. This page does not mint a new snapshot.
      </p>
    </header>
    <?php if (!Config::bundleSnapshotEnabled()) : ?>
      <p class="muted">Daily snapshot is off. Turn it on in Settings → Automation.</p>
    <?php endif; ?>
    <?php if ($error !== null) : ?>
      <p class="setup-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if ($rows === []) : ?>
      <p class="muted">No snapshot files yet.</p>
    <?php else : ?>
      <div class="table-scroll">
      <table class="ops-table">
        <thead>
          <tr><th>UTC day</th><th>Size</th><th>Written</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row) : ?>
            <tr>
              <td><?= htmlspecialchars($row['day']) ?></td>
              <td><?= htmlspecialchars((string) $row['bytes']) ?> B</td>
              <td><?= htmlspecialchars(gmdate('Y-m-d H:i:s', $row['mtime'])) ?></td>
              <td class="ops-row-actions">
                <a
                  class="btn btn-sm help-hover"
                  href="download_snapshot.php?day=<?= htmlspecialchars($row['day']) ?>"
                  <?= $dlHelp ?>
                >Download</a>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
                  <input type="hidden" name="delete_day" value="<?= htmlspecialchars($row['day']) ?>" />
                  <button type="submit" class="btn btn-sm help-hover"<?= $delHelp ?>>Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </main>
<?php
Layout::htmlClose(['assets/js/theme.js']);
