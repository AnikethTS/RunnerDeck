# Development

Running the app itself needs nothing but PHP — `./run.sh` works with a bare
checkout, no build step, no `composer install`. Composer is only used for
**dev tooling** (tests, static analysis, lint):

```bash
composer install          # pulls in phpunit, phpstan, phpcs (dev-only)
composer run test          # PHPUnit
composer run stan          # phpstan (level 8)
composer run cs            # phpcs, PSR-12
composer run cs-fix        # phpcbf, auto-fixes what it can
composer audit             # known CVEs in dependencies
```

`composer install` also points git at `.githooks/` (a plain shell
pre-commit hook, no Node/Husky involved), so every commit runs `phpcs`,
`phpstan`, and the test suite before it's allowed through. If you already
had `vendor/` installed before this hook existed, wire it up once with
`composer run hooks-install`. A commit made without dev tooling installed
skips the checks with a warning rather than blocking you.

The JS/CSS side has its own dev-only tooling, the same "never a runtime
dependency" deal as Composer above — `run.sh` doesn't need `node_modules/`
any more than it needs `vendor/`:

```bash
npm install       # pulls in eslint, stylelint, Playwright (dev-only)
npm run lint:js   # eslint on public/assets/app.js and public/assets/js/
npm run lint:css  # stylelint on public/assets/style.css
npm run e2e       # Playwright — see e2e/dashboard.spec.js
```

`npm run e2e` boots a real `php -S` server and drives the real dashboard in
a real (headless) browser — see the comment at the top of
`e2e/dashboard.spec.js` for exactly what is and isn't real in there (GitHub
itself is never contacted; runner data for the richer UI tests is supplied
by mocking `action=status` responses).

CI (`.github/workflows/ci.yml`) runs all of the above plus a boot smoke test
on `ubuntu-latest` and `macos-latest` (PHP 8.1), a dedicated Alpine (musl)
container (PHP 8.5, same digest as the Dockerfile), and the
[Docker image](docker.md) itself (`docker build` + env/lib assertions +
boot + hit `action=status` through the real port mapping) for every
push/PR — see [Platform support](getting-started.md#platform-support) —
with the default `GITHUB_TOKEN` scoped narrowly per job rather than
workflow-wide, and third-party actions pinned to commit SHAs rather than
mutable version tags. PHP lint and JS lint run as parallel jobs; workflows
are checked with `actionlint`. `.github/workflows/release.yml` publishes a
zipped GitHub Release (checksum + signed provenance) and pushes
`ghcr.io/anikethts/runnerdeck` whenever a `vX.Y.Z` tag is pushed — bump
the root `VERSION` file to match in the same commit, before tagging; it's
what the optional in-app update check compares against. Dependabot
(`.github/dependabot.yml`) keeps the dev-tooling Composer/npm
dependencies, the pinned Actions SHAs, and the Docker base image current
on a weekly schedule.

Security checks beyond linting: `.github/workflows/semgrep.yml` and
`.github/workflows/codeql.yml` (JavaScript and PHP) run static analysis on
every push/PR;
`.github/workflows/dependency-review.yml` blocks a PR that introduces a
known-vulnerable or newly license-incompatible dependency;
`.github/workflows/scorecard.yml` runs an OpenSSF Scorecard pass. GitHub
secret scanning + push protection and Dependabot security updates are both
enabled on the repo itself (not something in this codebase to configure,
but worth knowing they're on).

Want to contribute? See [CONTRIBUTING.md](../CONTRIBUTING.md) — it covers
PR expectations and the project's policy on AI-assisted contributions.

## Directory layout

```
runnerdeck/
  public/
    index.php       server-rendered dashboard page
    api.php         JSON API front controller (`action=` handlers in src/Api/)
    log_stream.php  Server-Sent Events log tailing
    login.php       optional TOTP login screen (see Security & safety)
    settings.php    scope/org/repo/label, auto-restart, crash webhook, login setup
    totp_setup.php  optional TOTP enroll / rotate
    assets/         app.js (dashboard), js/theme.js, style.css, optional logo.png
  src/
    bootstrap.php        autoload (`RunnerDeck\` → `src/`) + env bootstrap
    Config.php           env-based configuration
    Settings.php          reads/writes storage/settings.json (UI setup/Settings)
    AppLog.php             JSON error log at storage/runnerdeck.log (rotated)
    History.php            best-effort CPU/RAM history + SVG chart markup
    Csrf.php              session-bound CSRF token minting/verification
    Auth.php              optional TOTP login gate, lockout tracking
    Totp.php               RFC 6238 TOTP code generation/verification, no dependency
    RunnerPool.php        discovers runner dirs, checks process liveness
    SystemStats.php        whole-machine CPU/RAM usage, off the same ps scan
    GithubClient.php      shells out to `gh`
    UpdateCheck.php        opt-in check against this project's own GitHub Releases
    Provisioner.php       downloads, installs, and registers a runner
    ProcessDecision.php   pidfile vs live-process start/stop decisions
    ProcessControl.php    start/stop/restart, individual and pool-wide
    Dashboard.php         merges local + GitHub state into one snapshot
    DashboardView.php     first-paint HTML for stats, health banner, runner rows
    Layout.php            shared HTML chrome: theme cookie, topbar, assets
    Session.php           one session_start path (HttpOnly, SameSite, Secure)
    SecurityHeaders.php   CSP / frame / referrer headers for PHP responses
    Api.php               JSON API router and shared request helpers
    Api/                  one class per `api.php?action=` (`RunnerDeck\Api\`)
    Shell.php             timeout-guarded subprocess helper
  tests/            PHPUnit unit tests for the pure-logic pieces above
  .githooks/        pre-commit hook (cs/stan/test), wired up by `composer install`
  deploy/           optional process-supervision examples (systemd, launchd)
  run.sh            Linux/macOS entry point
  run.ps1           Windows entry point (forwards into WSL2)
  Dockerfile        alternative container entry point (see Docker doc)
  docker-compose.yml
```
