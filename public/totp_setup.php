<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Auth;
use RunnerDeck\Csrf;

if (Auth::isEnabled() && !Auth::isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verifyRequest()) {
        $error = 'Session expired — reload and try again.';
    } elseif (isset($_POST['generate'])) {
        Auth::beginTotpSetup();
    } elseif (isset($_POST['code'])) {
        if (Auth::confirmTotpSetup((string) $_POST['code'])) {
            header('Location: index.php');
            exit;
        }
        $error = 'Invalid code.';
    }
}

$secret = Auth::pendingTotpSecret();
$hasLogo = is_file(__DIR__ . '/assets/logo.png');
$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>RunnerDeck — Login setup</title>
  <?php if ($hasLogo) : ?>
    <link rel="icon" href="assets/logo.png" />
  <?php endif; ?>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>" />
</head>
<body>
  <header class="topbar">
    <?php if ($hasLogo) : ?>
      <img src="assets/logo.png" alt="Logo" class="logo" />
    <?php endif; ?>
    <h1>RunnerDeck</h1>
  </header>

  <main class="setup-main">
    <div class="setup-card">
      <?php if ($secret === null) : ?>
        <h2><?= Auth::isEnabled() ? 'Replace login secret' : 'Set up login' ?></h2>
        <p class="muted">
            <?= Auth::isEnabled()
            ? 'Generates a new secret. Codes from the current one stop working immediately once you confirm.'
            : 'Generates a secret for an authenticator app (Google Authenticator, Authy, 1Password, etc.).' ?>
        </p>
        <form method="post" class="settings-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <button type="submit" name="generate" value="1" class="btn btn-good">
            <?= Auth::isEnabled() ? 'Generate new secret' : 'Generate secret' ?>
          </button>
        </form>
      <?php else : ?>
        <h2>Confirm login setup</h2>
        <p class="muted">
          Add this secret to your authenticator app (manual/text entry), then
          enter the code it shows to confirm:
        </p>
        <p><code><?= htmlspecialchars(implode(' ', str_split($secret, 4))) ?></code></p>
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
          <button type="submit" class="btn btn-good">Confirm</button>
          <?php if ($error !== null) : ?>
            <p class="setup-error"><?= htmlspecialchars($error) ?></p>
          <?php endif; ?>
        </form>
        <form method="post" class="settings-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <button type="submit" name="generate" value="1" class="btn btn-sm">Start over</button>
        </form>
      <?php endif; ?>
      <p class="muted"><a href="index.php">Back to dashboard</a></p>
    </div>
  </main>
</body>
</html>
