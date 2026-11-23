<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use RunnerDeck\Security\Auth;
use RunnerDeck\Security\Csrf;
use RunnerDeck\Security\QrCode;
use RunnerDeck\Security\Totp;
use RunnerDeck\Presentation\Layout;
use RunnerDeck\Security\SecurityHeaders;

Auth::requirePageAccess();

$error = null;
$recoveryCodes = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verifyRequest()) {
        $error = 'Session expired — reload and try again.';
    } elseif (isset($_POST['generate'])) {
        Auth::beginTotpSetup();
    } elseif (isset($_POST['code'])) {
        if (Auth::confirmTotpSetup((string) $_POST['code'])) {
            $recoveryCodes = Auth::takeIssuedRecoveryCodes();
        } else {
            $error = 'Invalid code.';
        }
    }
}

$secret = Auth::pendingTotpSecret();
$csrfToken = Csrf::token();

Layout::htmlOpen('RunnerDeck — Login setup');
Layout::topbarStart();
Layout::topbarEndStart();
Layout::topbarEnd();
?>

  <main class="setup-main">
    <div class="setup-card">
      <?php if ($recoveryCodes !== []) : ?>
        <h2>Save these recovery codes</h2>
        <p class="muted">
          Each code works once if you lose the authenticator. Store them
          offline. They are not shown again.
        </p>
        <ul>
            <?php foreach ($recoveryCodes as $code) : ?>
              <li><code><?= htmlspecialchars($code) ?></code></li>
            <?php endforeach; ?>
        </ul>
        <p class="muted"><a href="index.php">Back to dashboard</a></p>
      <?php elseif ($secret === null) : ?>
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
          Scan the code with your authenticator app, or type the key below.
          Then enter the 6-digit code the app shows.
        </p>
        <div class="setup-qr"><?= QrCode::svg(Totp::provisioningUri($secret)) ?></div>
        <p class="setup-secret">
          <code id="totp-secret"><?= htmlspecialchars(implode(' ', str_split($secret, 4))) ?></code>
          <button type="button" id="totp-copy" class="btn btn-sm">Copy</button>
        </p>
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
<?php
if ($secret !== null && $recoveryCodes === []) :
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    echo '  <script' . SecurityHeaders::nonceAttr() . ">\n";
    echo '    (function () {' . "\n";
    echo '      var raw = ' . json_encode($secret, $jsonFlags) . ";\n";
    echo '      var button = document.getElementById("totp-copy");' . "\n";
    echo '      if (!button) return;' . "\n";
    echo '      button.addEventListener("click", function () {' . "\n";
    echo '        navigator.clipboard.writeText(raw).then(function () {' . "\n";
    echo '          button.textContent = "Copied";' . "\n";
    echo '          window.setTimeout(function () { button.textContent = "Copy"; }, 1500);' . "\n";
    echo '        });' . "\n";
    echo '      });' . "\n";
    echo '    })();' . "\n";
    echo "  </script>\n";
endif;
Layout::htmlClose(['assets/js/theme.js']);
