<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Auth;
use RunnerDeck\Csrf;
use RunnerDeck\Layout;

if (!Auth::isEnabled()) {
    header('Location: index.php');
    exit;
}
if (Auth::isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: index.php');
    exit;
}

$error = null;
$lockout = Auth::lockoutStatus();

if ($lockout['locked']) {
    $retryAfter = $lockout['retryAfter'] ?? 0;
    $minutes = intdiv($retryAfter, 60);
    $seconds = $retryAfter % 60;
    $error = sprintf('Too many attempts — try again in %dm %02ds.', $minutes, $seconds);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verifyRequest()) {
        $error = 'Session expired — reload and try again.';
    } elseif (Auth::attempt((string) ($_POST['code'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid code.';
    }
}

$csrfToken = Csrf::token();

Layout::htmlOpen('RunnerDeck — Log in');
Layout::topbarStart();
Layout::topbarEndStart();
Layout::topbarEnd();
?>

  <main class="setup-main">
    <div class="setup-card">
      <h2>Log in</h2>
      <p class="muted">Enter the code from your authenticator app.</p>
      <form method="post" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <label for="code">Code</label>
        <input
          type="text"
          id="code"
          name="code"
          inputmode="numeric"
          pattern="[0-9]*"
          maxlength="6"
          autocomplete="one-time-code"
          autofocus
          required
        />
        <button type="submit" class="btn btn-good">Log in</button>
        <?php if ($error !== null) : ?>
          <p class="setup-error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
      </form>
    </div>
  </main>
<?php
Layout::htmlClose(['assets/js/theme.js']);
