# Changelog

All notable changes to RunnerDeck are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versioning
follows [SemVer](https://semver.org/).

## [Unreleased]

### Added

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
