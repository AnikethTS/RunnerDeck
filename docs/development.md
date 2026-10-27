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
npm run audit     # npm audit (see scripts/npm-audit.mjs)
npm run e2e       # Playwright — see e2e/dashboard.spec.js
```

`npm run e2e` boots a real `php -S` server and drives the real dashboard in
a real (headless) browser — see the comment at the top of
`e2e/dashboard.spec.js` for exactly what is and isn't real in there (GitHub
itself is never contacted; runner data for the richer UI tests is supplied
by mocking `action=status` responses, and `action=queue` is mocked so
tests never walk live workflow runs).

CI (`.github/workflows/ci.yml`) runs all of the above plus a boot smoke test
on `ubuntu-latest` and `macos-latest` (PHP 8.1), a dedicated Alpine (musl)
container (PHP 8.5.12, same digest as the Dockerfile), and the
[Docker image](docker.md) itself (`docker build` + env/lib assertions +
boot + hit `action=status` through the real port mapping) for every
push/PR — see [Platform support](getting-started.md#platform-support) —
with the default `GITHUB_TOKEN` scoped narrowly per job rather than
workflow-wide, and third-party actions pinned to commit SHAs rather than
mutable version tags. PHP lint and JS lint run as parallel jobs; workflows
are checked with `actionlint`. PRs to `main` also run
`.github/workflows/changeradar.yml`: ChangeRadar 0.2.5 via the published
Action (SHA-pinned wrapper; latest `changeradar.jar` unless `version` is
set; fail-open; the Action posts artifact, compact comment, and Checks
notices; SARIF stays on the artifact, not Code Scanning).
`.github/workflows/release.yml` publishes a
zipped GitHub Release (checksum + signed provenance) and pushes
`ghcr.io/anikethts/runnerdeck` whenever a `vX.Y.Z` tag is pushed — bump
the root `VERSION` file to match in the same commit, before tagging; it's
what the optional in-app update check compares against. Dependabot
(`.github/dependabot.yml`) keeps the dev-tooling Composer/npm
dependencies and the Docker base image current on a weekly schedule, and
moves pinned Actions SHAs daily (including ChangeRadar). Leave the
analyzer `version` input unset so the JAR stays on the latest release.

Security checks beyond linting: `.github/workflows/semgrep.yml` and
`.github/workflows/codeql.yml` (JavaScript; PHP is covered by Semgrep) run static analysis on
every push/PR;
`.github/workflows/dependency-review.yml` blocks a PR that introduces a
known-vulnerable or newly license-incompatible dependency;
`.github/workflows/scorecard.yml` runs an OpenSSF Scorecard pass. `main` is
protected with a **repository ruleset** (not classic branch protection) so
Scorecard can read the rules with the default `GITHUB_TOKEN`. GitHub
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
    api.php         JSON API front controller (`action=` handlers in src/Http/Action/)
    log_stream.php  Server-Sent Events log tailing
    login.php       optional TOTP login screen (see Security & safety)
    logout.php      POST + CSRF; ends a TOTP session and redirects to login
    settings.php    scope/org/repo/label, GitHub App, automation, crash webhook, login, settings/host-bundle import
    history.php     24h CPU/RAM/disk-free charts (local samples)
    crashes.php     7-day crash bars and list
    outbox.php      webhook retry queue (CSRF drop; GET does not send)
    bundles.php     daily host-bundle files (download / CSRF delete)
    download_snapshot.php  one stored host-YYYY-MM-DD.json
    download_log.php      runner.log (+ rotated) download
    download_applog.php   storage/runnerdeck.log download
    download_settings.php redacted settings.json download
    download_bundle.php   host disaster-recovery JSON (optional webhook via POST)
    totp_setup.php  optional TOTP enroll / rotate
    health.php      unauthenticated `{"ok":true}` probe (no version; `ready` / `pressure` / `reboot_ready`)
    metrics.php     Prometheus text (local pool/pressure/queue cache/outbox; no GitHub)
    assets/         app.js (dashboard), js/theme.js, style.css, logo.png
  src/
    bootstrap.php   autoload (`RunnerDeck\` → `src/`) + env bootstrap
    Core/           Config, Settings, Auth, TOTP, CSRF, session, layout, log, shell
    GitHub/         gh client, GitHub App, queue cache, agent version, orphans
    Host/           pressure, host mode, metrics, cgroups, history, disk
    Pool/           runner dirs, start/stop, drain, rolling restart, crash state
    Alert/          crash webhook, outbox retry, syslog, queue/long-job alerts
    Ui/             dashboard snapshot and first-paint HTML
    Http/           API router (`RunnerDeck\Http\Api`)
    Http/Action/    one class per `api.php?action=` (`RunnerDeck\Http\Action\`)
  tests/            PHPUnit unit tests for the pure-logic pieces above
  .githooks/        pre-commit hook (cs/stan/test), wired up by `composer install`
  deploy/           php-fpm, Caddy/nginx, IP allowlist/mTLS; systemd/launchd
  run.sh            local php -S entry (also starts watch-auto-restart)
  bin/watch-auto-restart.php  loop for crash restart, ephemeral, scale-to-queue, idle-stop, alerts, daily bundle snapshot
  bin/drain-stop.php          wait-for-idle then SIGTERM (host reboot)
  run.ps1           Windows entry point (forwards into WSL2)
  Dockerfile        alternative container entry point (see Docker doc)
  docker-compose.yml
```
