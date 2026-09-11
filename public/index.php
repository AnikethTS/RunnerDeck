<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$snapshot = Dashboard::snapshot(5);
$csrfToken = Csrf::token();
$hasLogo = is_file(__DIR__ . '/assets/logo.png');
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
    <span class="org-tag">
      <?= htmlspecialchars(Config::org()) ?> &middot; label: <?= htmlspecialchars(Config::label()) ?>
    </span>
  </header>

  <div id="health-banner" class="banner" hidden></div>

  <main>
    <div class="toolbar">
      <button id="btn-refresh" class="btn">Refresh</button>
      <span id="last-updated" class="muted"></span>
      <span class="spacer"></span>
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

  <script>
    window.__SNAPSHOT__ = <?= json_encode($snapshot) ?>;
    window.__CSRF__ = <?= json_encode($csrfToken) ?>;
  </script>
  <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>" defer></script>
</body>
</html>
