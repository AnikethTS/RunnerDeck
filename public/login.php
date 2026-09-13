<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (!Auth::isEnabled()) {
    header('Location: index.php');
    exit;
}
if (Auth::isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verifyRequest()) {
        $error = 'Session expired — reload and try again.';
    } elseif (Auth::lockoutStatus()['locked']) {
        $error = 'Too many attempts — try again in a few minutes.';
    } elseif (Auth::attempt((string) ($_POST['code'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid code.';
    }
}

$hasLogo = is_file(__DIR__ . '/assets/logo.png');
$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>RunnerDeck — Log in</title>
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
</body>
</html>
