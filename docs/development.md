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

`npm run e2e:update-shots` regenerates the README/landing PNGs in dark
mode (and copies them into `docs/screenshots/`). Generate them in the
same Playwright image CI uses
(`mcr.microsoft.com/playwright:v1.64.1-noble`); host Fedora/macOS fonts
will otherwise fail the pixel compare.

CI prints `[1/N]` as each Playwright and PHPUnit test starts, and stops
the browser suite after 5 failures. A click that does not land fails in
5 seconds.

`npm run e2e` boots a real `php -S` server and drives the real dashboard in
a real (headless) browser — see the comment at the top of
`e2e/dashboard.spec.js` for exactly what is and isn't real in there (GitHub
itself is never contacted; runner data for the richer UI tests is supplied
by mocking `action=status` responses, and `action=queue` is mocked so
tests never walk live workflow runs).

CI (`.github/workflows/ci.yml`) also runs **Shellcheck** on `run.sh`,
`scripts/ci/*.sh`, and any `bin/*.sh`, and **gitleaks** on the git history
(placeholders in tests and the Settings PEM hint are allowlisted in
`.gitleaks.toml`). Playwright
compares dashboard, Settings, Add Runner, and live-log screenshots to
`.github/screenshots/` (and those files must match `docs/screenshots/`).
`.github/workflows/release.yml` scans the published
`ghcr.io/anikethts/runnerdeck` image with Trivy (CRITICAL/HIGH, unfixed
ignored) after push.

The `e2e-real-runner` job registers a real ephemeral runner on Linux, macOS and Windows hosted runners, dispatches `scripts/ci/e2e-probe-workflow.yml` onto it, and waits for the job to pass. It needs a dedicated, throwaway test repository containing a copy of `scripts/ci/e2e-probe-workflow.yml` at `.github/workflows/runnerdeck-e2e.yml` (copied once by hand, so the token never needs the Workflows permission), a repository variable `E2E_REPO` (`owner/repo`), and a secret `E2E_RUNNER_TOKEN`: a fine-grained token limited to that one repo with Administration, Actions and Contents read/write. Keep that repo private and empty of anything else, since a runner executes its workflows. Without the secret or variable the job fails. Pull requests from forks skip it, because they never receive the secret. Fork pull requests never get the secret.

The `e2e-live` job runs `e2e-live/dashboard-live.spec.js` with Playwright against the real dashboard on Ubuntu (latest, 22.04, 22.04 arm), macOS (Apple silicon and Intel), Windows (latest and 2022), and Fedora, using the same test repo and secrets as `e2e-real-runner`. Fedora has no hosted runner, so the dashboard and its runners run in a `fedora:44` container while Playwright drives it from the host (`E2E_EXTERNAL_SERVER=1`). It clicks Add Runner, Start, Stop, Restart, Pause/Resume AR, View live log, Rename, Clear work, Export JSON, Pool size Apply, the bulk actions, Stop All, Start All, Rolling Restart, Maintenance, Drain All, Delete, and the Settings save, and checks the runner state on GitHub after each. It also runs a real workflow job and times every header tab. Not covered: Upgrade agents and Safe reboot. Run it locally with `E2E_REPO`, `GH_TOKEN` and `E2E_TAG` set and `npm run e2e:live`; it registers real runners on your machine.

### Choosing which CI runs

Pull requests get the fast set by default: lint, the secret scan, and the unit suite with the coverage gate. Add labels to run more, as in other projects that use this pattern:

| Label | Adds |
|---|---|
| `ci:fast` | Lint, secret scan, unit suite with coverage (always on) |
| `ci:unit` | Every `Unit ·` job: Linux, macOS, Windows subset, distro containers |
| `ci:quality` | Random-order test run |
| `ci:smoke` | Boot smoke tests: hosts, Alpine, Docker image |
| `ci:e2e` | Browser UI tests with a mocked API |
| `ci:live` | Live dashboard and real-runner tests on Ubuntu, macOS and Windows (uses GitHub API quota) |
| `ci:live-extended` | Live dashboard tests on older OS versions and Fedora (uses GitHub API quota) |
| `ci:full` | Everything above except `ci:live-extended`. **Required before merging.** |

Pushes to `main`, the nightly run and manual runs always run everything. The `CI · Merge gate` job passes only when every job that ran passed, and on a pull request only when `ci:full` is set. Adding or removing a label re-runs CI. Fork pull requests never run the live jobs, because they do not receive the secrets.

CI also enforces a minimum line coverage (`scripts/ci/coverage-gate.php`, currently 75%), runs the suite in random order with three seeds, and re-runs everything nightly. Raise the coverage floor whenever coverage improves; never lower it to land a change.

The Windows code paths are unit-tested on Linux with `Platform::fakeWindows()` and a faked `Shell` (`tests/WindowsSupportTest.php`); the `windows-test` CI job runs that file and `scripts/ci/windows-smoke.php`, which drives the real PowerShell, CIM and `taskkill` calls on `windows-latest` and `windows-2022`. The rest of the suite is Unix-only and is not run there.

CI (`.github/workflows/ci.yml`) runs all of the above plus a boot smoke test
on `ubuntu-latest`, `ubuntu-22.04`, `macos-latest`, `macos-15`, and Intel `macos-15-intel` (PHP 8.1),
PHPUnit on those hosts plus `ubuntu-22.04-arm`, distro-`apt` PHPUnit on
Ubuntu 22.04 and Debian 12, distro-`dnf` PHPUnit on Fedora, Rocky 9, and
RHEL UBI 9, a dedicated Alpine (musl)
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
    assets/         app.js (live dashboard), js/theme.js (theme and help tips), style.css
                    (shared --page-gutter / --page-max; 900 / 720 / 520 breakpoints), logo.png
  src/
    bootstrap.php   autoload (`RunnerDeck\` → `src/`) + env bootstrap
    Core/           Config, Settings, Auth, TOTP, CSRF, session, layout, log, shell
    GitHub/         gh client, GitHub App, queue cache, agent version, orphans, backoff/clock/quota
    Host/           pressure, host mode, metrics, cgroups, history, host bundle
    Pool/           runner dirs, start/stop, drain, rolling restart, crash state, log retention
    Alert/          crash webhook, disk alert, outbox retry, syslog, queue/long-job alerts
    Ui/             dashboard snapshot and first-paint HTML
    Http/           API router (`RunnerDeck\Http\Api`)
    Http/Action/    one class per `api.php?action=` (`RunnerDeck\Http\Action\`)
  tests/            PHPUnit unit tests for the pure-logic pieces above
  .githooks/        pre-commit hook (cs/stan/test), wired up by `composer install`
  deploy/           php-fpm, Caddy/nginx, IP allowlist/mTLS; systemd/launchd
  run.sh            local php -S entry (also starts watch-auto-restart)
  bin/watch-auto-restart.php  loop for crash restart, ephemeral, scale-to-queue, idle-stop, alerts, daily bundle snapshot, log retention
  bin/drain-stop.php          wait-for-idle then SIGTERM (host reboot)
  scripts/ci/                 CI-only scripts (distro PHPUnit, smoke status, pinned gh install)
  run.ps1           Windows entry point (native; -Wsl forwards into WSL2)
  Dockerfile        alternative container entry point (see Docker doc)
  docker-compose.yml
```
