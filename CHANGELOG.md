# Changelog

All notable changes to RunnerDeck are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versioning
follows [SemVer](https://semver.org/).

## [Unreleased]

### Added

- The dashboard shows a **Recent errors** panel from `storage/runnerdeck.log`
  (start/provision/`gh` failures already written there, redacted).
- Settings has a **Test** button next to the crash-loop webhook URL field —
  sends a clearly-labeled test notification to whatever's currently typed,
  without needing to save first, so Slack/Discord/generic delivery can be
  confirmed before relying on it.

### Security

- Session cookies always go through `Session::start()`: `HttpOnly`,
  `SameSite=Lax`, and `Secure` when the request is HTTPS (including
  `X-Forwarded-Proto` behind a TLS proxy). Successful TOTP login still
  calls `session_regenerate_id(true)`.
- PHP responses send `X-Frame-Options: DENY`, `X-Content-Type-Options:
  nosniff`, `Referrer-Policy: no-referrer`, and a CSP that allows only
  same-origin assets plus a per-request script nonce.

### Changed

- Landing page and README screenshots retaken from a live pool (dashboard,
  live log, add runner, Settings), plus a short demo GIF.
- First-run setup is a normal POST to `index.php` (same `SaveSettingsAction`
  path as Settings), with errors rendered in the page instead of `alert()`.
- The dashboard paints stats, health banner, and runner rows in PHP on the
  first response so the table is not empty until JavaScript runs.
- PHPStan is at level 8.
- Status polls (5s) pause while the dashboard tab is hidden, then fetch
  once when it becomes visible again. Auto-restart is still a client
  `POST action=start` on those polls, so it also waits until a dashboard
  tab is in the foreground. Whole-machine CPU/RAM comes from the same
  snapshot (`action=system` is still available for scripts).
- Dashboard snapshots call `gh api --paginate` for runners first and only
  run `gh auth status` when that fails, so a healthy poll is one GitHub
  CLI process instead of two. Pages beyond GitHub's default 30 are
  included (`per_page=100`).
- The runner table is not rebuilt from `innerHTML` when the rows, filter,
  sort, and selection have not changed, so a 5s poll does not steal
  focus from the filter box.
- CI splits PHP/JS lint, trims the PHPUnit matrix to Ubuntu 8.1/8.5 and
  macOS 8.5, smokes PHP 8.1 on the host and PHP 8.5 Alpine (same digest as
  the Dockerfile), asserts Docker env/libs, runs `actionlint` and
  `npm audit`, and publishes `ghcr.io/anikethts/runnerdeck` on version tags.

### Fixed

- Linux provision now runs GitHub's `bin/installdependencies.sh` after
  extract (skipped on Alpine; the Docker image installs those libs via
  `apk` instead) so a bare host gets libicu/libkrb5 instead of a missing
  shared-library error when the runner binary starts.
- Delete renames the slot out of the pool before `rm -rf` (60s timeout),
  so a timed-out delete cannot leave a half-wiped directory that still
  looks like a runner.
- Docker: `RUNNER_ALLOW_RUNASROOT=1` so `config.sh` can run as root;
  `docker.md` no longer suggests in-container `gh auth login` while the
  compose file mounts `~/.config/gh` read-only.

## [1.3.0] - 2026-09-21

### Added

- Optional crash-loop webhook (`RUNNERDECK_CRASH_WEBHOOK_URL`, Settings):
  POSTs a notification the moment a crash-loop is flagged, so it's not
  only visible if a dashboard tab happens to be open. Slack and Discord
  incoming webhook URLs are detected automatically and get their native
  payload shape; anything else gets a plain JSON payload.

### Changed

- Settings moved from a modal on the dashboard to its own page
  (`settings.php`), matching the existing `login.php`/`totp_setup.php`
  pattern: a server-rendered form. Reflows properly on mobile instead of
  a fixed-width dialog, and removes the settings-modal open/close/backdrop
  JS entirely.
- Local/GitHub mismatch streaks are counted in `CrashState`
  (`mismatch_flagged`) rather than in JavaScript. CPU/RAM history charts
  are drawn as SVG in PHP and embedded in the status snapshot, so the UI
  dropped its extra `action=history` poll. Theme preference is a cookie
  so the first paint can set `data-theme` without a flash.

### Fixed

- The light/dark toggle and cookie-backed `data-theme` now apply on
  Settings (and login / TOTP setup), not only the dashboard. Shared
  chrome lives in `src/Layout.php`; `assets/js/theme.js` is loaded on
  those pages without pulling in the rest of the dashboard JS.

- TOTP login lockouts now show the exact remaining wait time instead of a vague "few minutes" message.
- `ApiTest`, `ProvisionerTest`, `ProcessControlTest`, and `CrashWebhookTest`
  didn't isolate `RUNNERDECK_SETTINGS_FILE`, so their failure-path fixtures
  were writing real entries into this repo's own `storage/runnerdeck.log`
  on every `composer run test`. No behavior change, test-only.

### Security

- `action=status` (a GET, which has no CSRF check by design) briefly
  called `ProcessControl::startIndividual()` directly for auto-restart,
  meaning any page loaded in the same browser — a plain `<img src>` to
  that URL — could make RunnerDeck spawn a real runner process with no
  user interaction and no token. Reverted: the GET only ever decides
  `should_auto_restart`; the client still has to `POST action=start`
  (CSRF-protected) for the restart to actually happen. Added a regression
  test (`DashboardTest::testSnapshotNeverSpawnsAProcessEvenWhenAutoRestartShouldFire`)
  asserting `Dashboard::snapshot()` never spawns a process, so this can't
  silently come back.

## [1.2.0] - 2026-09-16

### Added

- Failures from `gh`, `config.sh`, and start/stop are appended as JSON
  lines to `storage/runnerdeck.log` (rotated into `runnerdeck.log.1` at
  256 KiB). Secrets (TOTP, `gh` tokens, `--token` values) are stripped
  before write. Polling `gh` errors are throttled to once a minute so a
  down API cannot fill the disk.
- Unit tests for start/stop/provision: `ProcessDecision` covers pidfile vs
  live-listener decisions, and `ProcessControl`/`Provisioner` run against
  temp dirs with a fake `Shell` so checksum failure, config.sh, and
  start/stop never spawn a real runner or call GitHub.
- The log download can now be narrowed to a time range (`from`/`to`
  fields in the log viewer) instead of only the full file. Filters by
  whatever timestamps the runner's own console output includes;
  untimestamped lines (most job output) inherit the last-seen timestamp.
- **RunnerDeck's first community-contributed features**, all via
  `up-for-grabs` issues:
  - An **Export JSON** toolbar button downloads the full current runner
    list — unaffected by the filter box — as a timestamped `.json` file.
  - `/` focuses the runner filter and `r` triggers Refresh, guarded
    against firing while typing in an editable field, modifier-key
    combinations, IME composition, and key-repeat.
  - The live log viewer gained a search field that highlights matches as
    you type, including newly streamed lines — built with text nodes and
    `<mark>`, never `innerHTML`, so log content stays safe either way.
  - A web app manifest and bundled icons (from `.github/logo.png`) let
    supported browsers install RunnerDeck via "Add to Home Screen" —
    browser- and HTTPS-dependent, no service worker or offline support.
  - The "Local process" column header now has independent CPU and
    Uptime sort controls, instead of CPU-only.
- `API.md`: a full reference for `api.php`'s actions plus the two
  standalone log endpoints, including how to get a CSRF token from a
  script instead of a browser session (`action=csrf_token`).
- A `Dockerfile` and `docker-compose.yml`, as an alternative to the
  PHP-CLI/WSL2 path — see the README's [Docker](README.md#docker) section.
  Runner pool/settings/history relocate to a mounted `/data` volume; the
  container binds `0.0.0.0` internally (only there — every other install
  still binds `127.0.0.1`) with the host-side port mapping pinned to
  `127.0.0.1:8090:8090` to stay loopback-only end to end. Runner jobs that
  need their own `docker` command need DinD or a mounted socket, neither
  of which this image sets up. Podman works as a drop-in (`podman compose
  up --build`) — the volume mounts carry a `:z` SELinux relabel option for
  rootless Podman on SELinux-enforcing distros; Docker ignores it
  elsewhere.
- Optional TOTP login, for anyone hosting their instance somewhere beyond
  localhost behind a TLS-terminating reverse proxy — see the README's
  [Hosting remotely](README.md#hosting-remotely) section. Set up either via
  `php bin/setup-totp.php` or **Settings → Login** in the dashboard itself.
  Off by default, zero behavior change for the existing localhost-only
  setup. Single-user only, no accounts/roles. A dependency-free RFC 6238
  implementation (`src/Totp.php`) verifies codes; 5 wrong codes in a row
  locks login out for 5 minutes (`src/Auth.php`). The secret is stored the
  same way as every other setting, in `storage/settings.json`.
- CI/CD hardening: `main` now requires all CI checks to pass (and blocks
  force-pushes) before anything can merge — previously nothing enforced
  that. Workflows also gained `timeout-minutes` on every job,
  `concurrency`-based cancellation of superseded runs on rapid pushes, and
  dependency caching (`composer.lock`/`package-lock.json` are now
  committed so `setup-php`/`setup-node` can cache reliably, and `npm ci`
  replaces `npm install` in CI). The Docker smoke test now builds via
  `docker/build-push-action` with GitHub Actions layer caching instead of
  a from-scratch `docker build` every run.

- Crash-loop detection and auto-restart now live server-side
  (`src/CrashState.php`), instead of independently in every open browser
  tab. `action=status` returns `crash_flagged`/`just_flagged`/
  `should_auto_restart` per runner (see `API.md`); the client reacts to
  these instead of tracking its own state. A toast notification now fires
  the moment a runner is flagged as crash-looping, in addition to the
  existing row badge — previously the only signal was that inline badge,
  easy to miss if you weren't looking at that row. The auto-restart toggle
  moved from an unbound toolbar checkbox (persisted only via
  `localStorage`, per-browser) to a real setting
  (`RUNNERDECK_AUTO_RESTART`, Settings dialog), consistent everywhere.

### Changed

- PHP classes live in the `RunnerDeck\` namespace, loaded by a small
  `spl_autoload_register` in `src/bootstrap.php` (`RunnerDeck\Foo` →
  `src/Foo.php`). Composer is still dev-only (PSR-4 autoload-dev for tests).
  HTTP URLs and JSON bodies are unchanged.
- `setup-php` in CI now gets `GITHUB_TOKEN` so unauthenticated GitHub
  rate limits are less likely to stall PHP downloads on macOS. Boot-smoke
  job timeout is 15 minutes (was 5) so a slow macos-14 PHP install cannot
  cancel the required check.
- `public/api.php` is a thin front controller. Each `action=` is a
  handler class under `src/Api/`, dispatched by `src/Api.php`. URLs,
  CSRF, auth gating, and response bodies are unchanged.

### Fixed

- The live log viewer now stops reconnecting and returns to the login page
  when a TOTP-authenticated session expires.

## [1.1.0] - 2026-09-12

### Added

- `bin/doctor.php`, a preflight check for PHP version/extensions, `bash`/
  `curl`/`tar`, `gh` install and auth status, GitHub API access for the
  configured scope, and pool/storage directory permissions.
- CI security hardening: `composer audit` in the lint job, a Semgrep static
  analysis pass (`p/security-audit` + `p/php`), and a Dependency Review
  check on PRs. Enabled Dependabot security updates on the repo.
- JS/CSS dev tooling (`package.json`, dev-only, same as Composer): eslint
  and stylelint, plus a Playwright E2E suite (`e2e/dashboard.spec.js`)
  covering first-run setup, the filter box, sorting, bulk-select, and the
  status-mismatch flag against a real browser and a real PHP backend.
- `run.sh` now prints the history DB's resolved path, whether
  `pdo_sqlite` is available, and whether it's writable, right alongside
  the "RunnerDeck on http://..." startup line.
- The history chart now says so when `pdo_sqlite` isn't installed,
  instead of showing "collecting data…" forever with no explanation.
- Two new stat cards, **System CPU** and **System RAM**, showing
  whole-machine usage (`src/SystemStats.php`) alongside the existing
  per-runner stats.
- An opt-in **update check** (`RUNNERDECK_CHECK_UPDATES`, off by
  default): compares this install's `VERSION` file against this
  project's latest GitHub Release and shows a header badge if a newer
  one exists. Toggle it from Settings. `src/UpdateCheck.php`.

### Changed

- The CPU/RAM history SQLite file now lives at `storage/db/history.sqlite`
  instead of directly under `storage/`, alongside `settings.json`.
- `public/assets/app.js` (826 lines, one IIFE) is now split into ES
  modules under `public/assets/js/` (api, utils, reliability, table,
  history-chart, stats, modals), with `app.js` as the entry point.
  No build step involved — browsers load ES modules natively. Purely
  structural; no behavior change.
- `Dashboard::snapshot()` was hitting GitHub's runners API twice per
  poll — once as an access probe inside `GithubClient::authStatus()`
  whose result was thrown away, once for real in `listRunners()`.
  Removed the redundant call (`GithubClient::checkLogin()` now covers
  the login check; `listRunners()` doubles as the access check).
  `authStatus()` still exists, probe included, for `bin/doctor.php`'s
  one-shot use. The auto-refresh interval also dropped from 12s to 5s,
  safe now that each poll costs about half the GitHub API calls it did.
- The System CPU/RAM stat cards now poll independently every 2s via a
  new `action=system` endpoint, instead of waiting on the main 5s
  runner/GitHub poll — they're pure local `ps`/`nproc`/`/proc` reads
  with no GitHub API cost, so there's no reason to cap them at the
  same interval as data that does.

### Fixed

- `api.php?action=status` would hard-crash instead of returning a clean
  JSON error if `gh` was authenticated but RunnerDeck itself wasn't
  configured yet — found while building the E2E harness.

## [1.0.0] - 2026-09-11

First tagged release. Everything below had already shipped to `main`
incrementally; this tag marks the point where RunnerDeck is considered
stable enough to depend on a specific version of.

### Added

- Runner lifecycle management: add, rename, and delete runners from the UI,
  individually or in bulk (checkbox multi-select with bulk start/stop/delete).
- Live status for each runner: GitHub-reported state (online/offline,
  busy/idle) side by side with the actual local process state, live
  CPU/RAM/uptime, and a rolling one-hour history chart backed by a local
  SQLite file.
- A row is flagged when local and GitHub-reported status disagree for
  several consecutive polls, and an optional auto-restart can bring a
  crashed runner back up on its own, with backoff.
- Live log tailing per runner, a filter box for the runner table, and a
  full log download separate from the tail.
- Org-scoped or single-repo-scoped runner management, configurable from an
  in-UI first-run setup screen and a Settings dialog — no `.env` file
  required to get started.
- Cross-platform: native on Linux and macOS, Windows via WSL2, CI-tested
  down to Alpine/musl.
- CSRF-protected API, SHA-pinned GitHub Actions, and Dependabot for both
  Composer and Actions dependencies.
