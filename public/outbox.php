<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Alert\WebhookOutbox;
use RunnerDeck\Core\Auth;
use RunnerDeck\Core\Csrf;
use RunnerDeck\Core\Layout;

Auth::requirePageAccess();

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::verifyRequest()) {
        $error = 'Session expired — reload and try again.';
    } else {
        $id = (string) ($_POST['drop_id'] ?? '');
        if (WebhookOutbox::drop($id)) {
            header('Location: outbox.php');
            exit;
        }
        $error = 'Could not drop that item.';
    }
}

$csrfToken = Csrf::token();
$items = WebhookOutbox::pending();
$dropHelp = Layout::hoverHelp(
    'Remove this item so it will not retry. A later real event can enqueue again.'
);

Layout::htmlOpen('RunnerDeck — Outbox');
Layout::topbarStart();
Layout::topbarEndStart();
Layout::pageNav('outbox.php');
Layout::logoutForm($csrfToken);
Layout::topbarEnd();
?>
  <main class="ops-main">
    <header class="settings-header">
      <h2>Webhook outbox</h2>
      <p class="muted">
        Failed crash-webhook posts waiting to retry. This page does not send.
        Drop removes the item so it will not retry.
      </p>
    </header>
    <?php if ($error !== null) : ?>
      <p class="setup-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if ($items === []) : ?>
      <p class="muted">Nothing waiting to retry.</p>
    <?php else : ?>
      <div class="table-scroll">
      <table class="ops-table">
        <thead>
          <tr>
            <th>Event</th>
            <th>Host</th>
            <th>Runner</th>
            <th>Age</th>
            <th>Next try</th>
            <th>Tries</th>
            <th>Last error</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item) : ?>
            <tr>
              <td><?= htmlspecialchars((string) $item['event']) ?></td>
              <td><?= htmlspecialchars((string) $item['host']) ?></td>
              <td><?= htmlspecialchars((string) ($item['runner'] ?? '—')) ?></td>
              <td><?= htmlspecialchars((string) max(0, time() - (int) $item['created_at'])) ?>s</td>
              <td><?= htmlspecialchars((string) max(0, (int) $item['next_at'] - time())) ?>s</td>
              <td><?= htmlspecialchars((string) $item['attempts']) ?></td>
              <td><?= htmlspecialchars((string) $item['last_error']) ?></td>
              <td>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
                  <input type="hidden" name="drop_id" value="<?= htmlspecialchars((string) $item['id']) ?>" />
                  <button type="submit" class="btn btn-sm help-hover"<?= $dropHelp ?>>Drop</button>
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
